<?php

namespace Goldnead\Marketing\Series;

use Carbon\CarbonImmutable;
use Goldnead\Events\Facades\Events as EventsFacade;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Holds the concert-mail series together: for every template campaign (status
 * `series`) and every upcoming term, one child campaign plus one LeadHub
 * segment around the venue's postal code.
 *
 * **Idempotent is the whole design.** The key is `source_key =
 * "occurrence:<uuid>"` plus `series = <handle of the template>`; a second run
 * finds the child it wrote before and only pulls `meta['event']`, the
 * scheduled time and the segment rule even with what the term says now.
 * Everything may call everything: the events listeners, the daily command,
 * the save of a template. Twice is not a mistake, it is the normal case.
 *
 * **Children are ordinary campaigns** with two extra fields. Content, subject,
 * list, layout, mail class come from the template at clone time; from then on
 * they are the child's own, and a child that was approved (`scheduled`) keeps
 * its status through later syncs. `sending`/`sent` children are never touched
 * again — neither by an update nor by the cleanup.
 *
 * **Without statamic-events nothing here runs.** {@see self::available()}
 * guards the whole class, and the listeners behind it are registered only
 * when the sibling's classes exist.
 */
class SeriesSync
{
    /** The settings of `meta['series']`, with the Vorgaben from the spec. */
    public const DEFAULT_SETTINGS = [
        'radius_km' => 50,
        'days_before' => 7,
        'send_time' => '10:00',
        'event_ids' => [],
        'country' => 'DE',
        // What the send time hangs on: the concert (N days before it) or the
        // start of the presale (N days after it).
        'anchor' => self::ANCHOR_CONCERT,
        'days_after_presale' => 0,
        // "Weitere Konzerte": later terms near this one, shown in the mail.
        // Display only — the audience stays the circle around the main term.
        'more_enabled' => true,
        'more_radius_km' => 100,
        'more_limit' => 3,
    ];

    public const ANCHOR_CONCERT = 'concert';

    public const ANCHOR_PRESALE = 'presale';

    /** @var array{created: int, updated: int, removed: int, skipped_no_postal_code: int, skipped_no_presale: int} */
    public const EMPTY_RESULT = [
        'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped_no_postal_code' => 0, 'skipped_no_presale' => 0,
    ];

    /** The German weekday names `{{ event:weekday }}` renders, Monday first. */
    protected const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    /**
     * The upcoming, not cancelled terms of one sync run, loaded once and
     * shared by every "more events" lookup in it. Null outside a run.
     *
     * @var Collection<int, Occurrence>|null
     */
    protected ?Collection $upcoming = null;

    /** Does the installed LeadHub know `managed_by`? Asked once per instance. */
    protected ?bool $managedBySupported = null;

    /**
     * Memoised {@see self::available()}. See SequenceSync for why the check is
     * memoised at all: behind it stands a `Schema::hasTable()`, which goes to
     * the database on every call rather than being cached by Laravel.
     */
    protected static ?bool $available = null;

    public function __construct(
        protected CampaignRepository $campaigns,
        protected SegmentRepository $segments,
    ) {}

    /**
     * Is there a term addon to read from?
     *
     * Both halves: the classes (composer-level presence) and the occurrences
     * table (migrations ran). An installed-but-unmigrated sibling reaches the
     * second and is answered like a missing one — the honest difference
     * between the two is nothing a daily cron should act on.
     */
    public static function available(): bool
    {
        if (static::$available === null) {
            static::$available = class_exists(EventsFacade::class)
                && Schema::hasTable('event_occurrences');
        }

        return static::$available;
    }

    /** Drop the memo. For tests, and for anything that migrates inside one process. */
    public static function forgetAvailability(): void
    {
        static::$available = null;
    }

    /**
     * One occurrence, out of band — what the events listeners call.
     *
     * A cancelled term takes its unsent children and their segments with it; a
     * scheduled one is brought in line for every template that wants its event.
     *
     * @return array{created: int, updated: int, removed: int, skipped_no_postal_code: int, skipped_no_presale: int}
     */
    public function syncOccurrence(Occurrence $occurrence): array
    {
        if (! static::available()) {
            return self::EMPTY_RESULT;
        }

        $this->upcoming = null;

        if ($occurrence->isCancelled()) {
            $result = self::EMPTY_RESULT;
            $result['removed'] = $this->removeForOccurrence($occurrence->uuid, $this->campaigns->all());

            return $result;
        }

        $result = self::EMPTY_RESULT;

        foreach ($this->templates() as $template) {
            $this->tallyInto($result, $this->syncOne($template, $occurrence));
        }

        return $result;
    }

    /**
     * Every template against every term, plus the cleanup: children whose
     * template or term is gone.
     *
     * @return array{created: int, updated: int, removed: int, skipped_no_postal_code: int, skipped_no_presale: int}
     */
    public function syncAll(): array
    {
        if (! static::available()) {
            return self::EMPTY_RESULT;
        }

        $result = self::EMPTY_RESULT;
        $all = $this->campaigns->all();
        $templates = $this->templatesOf($all);

        foreach ($templates as $template) {
            $this->merge($result, $this->syncTemplate($template));
        }

        $this->cleanUpOrphans($templates, $all, $result);

        return $result;
    }

    /**
     * One template against every upcoming term. Public because saving a
     * template in the CP wants exactly this and nothing else.
     *
     * @return array{created: int, updated: int, removed: int, skipped_no_postal_code: int, skipped_no_presale: int}
     */
    public function syncTemplate(Campaign $template): array
    {
        if (! static::available() || ! $template->isSeries()) {
            return self::EMPTY_RESULT;
        }

        $result = self::EMPTY_RESULT;
        $this->upcoming = null;

        // Include cancelled dates: they are exactly the ones whose children
        // have to go. Deleted rows are the cleanup's half of the job.
        $occurrences = EventsFacade::occurrences(['upcoming' => true, 'include_cancelled' => true]);

        foreach ($occurrences as $occurrence) {
            /** @var Occurrence $occurrence */
            if ($occurrence->isCancelled()) {
                $result['removed'] = $result['removed'] + $this->removeForOccurrence($occurrence->uuid, $this->campaigns->all());

                continue;
            }

            $this->tallyInto($result, $this->syncOne($template, $occurrence));
        }

        return $result;
    }

    /**
     * Template × term: create, bring in line, or leave alone.
     *
     * @return string one of created|updated|skipped_no_postal_code|untouched
     */
    protected function syncOne(Campaign $template, Occurrence $occurrence): string
    {
        // What a visitor may be shown — the same rule the facade query
        // applies, asked again here because the listeners reach this method
        // without going through it (a date of a draft event fires
        // OccurrenceScheduled too, and a mail for an unpublished concert is
        // worse than no mail).
        if (! $occurrence->event?->isPubliclyReadable() || ! $this->wantsEvent($template, $occurrence)) {
            // An unpublished event, or one the template no longer names: an
            // unsent child (approved or not) goes like a cancelled one.
            return $this->removeChild($this->childHandle($template, $occurrence)) ? 'removed' : 'untouched';
        }

        if (blank($occurrence->venue_postal_code)) {
            return 'skipped_no_postal_code';
        }

        $settings = $this->settings($template);

        // A presale series needs a presale date to hang the send on. A term
        // without one is counted like one without a postal code; a waiting
        // child whose presale date was taken away goes, because its send time
        // no longer has a basis.
        if ($settings['anchor'] === self::ANCHOR_PRESALE && $this->presaleStart($occurrence) === null) {
            $this->removeChild($this->childHandle($template, $occurrence));

            return 'skipped_no_presale';
        }

        $child = $this->campaigns->find($this->childHandle($template, $occurrence));

        if ($child && in_array($child->status, [Campaign::STATUS_SENDING, Campaign::STATUS_SENT], true)) {
            return 'untouched';
        }

        $segmentHandle = $this->ensureSegment($template, $occurrence, $settings);

        if (! $child) {
            // Nothing for a term that already started; the approval guard
            // would refuse it anyway, and a campaign nobody may send is noise
            // on the waiting list.
            if ($occurrence->starts_at->isPast()) {
                return 'untouched';
            }

            $this->campaigns->save($this->cloneTemplate($template, $occurrence, $segmentHandle, $settings));

            return 'created';
        }

        if (! in_array($child->status, [Campaign::STATUS_AWAITING_APPROVAL, Campaign::STATUS_SCHEDULED], true)) {
            return 'untouched';
        }

        return $this->pullChildEven($template, $child, $occurrence, $settings, $segmentHandle) ? 'updated' : 'untouched';
    }

    /**
     * The clone of the template for one term: everything the editor wrote,
     * aimed at one city, waiting for one approval.
     */
    protected function cloneTemplate(
        Campaign $template,
        Occurrence $occurrence,
        string $segmentHandle,
        array $settings,
    ): Campaign {
        return new Campaign(
            handle: $this->childHandle($template, $occurrence),
            name: $template->name.' ('.trim((string) ($occurrence->venue_city ?: $occurrence->venue_postal_code)).')',
            subject: $template->subject,
            variantSubject: $template->variantSubject,
            preheader: $template->preheader,
            fromName: $template->fromName,
            fromEmail: $template->fromEmail,
            replyTo: $template->replyTo,
            listHandle: $template->listHandle,
            segmentHandle: $segmentHandle,
            templateHandle: $template->templateHandle,
            content: $template->content,
            status: Campaign::STATUS_AWAITING_APPROVAL,
            scheduledAt: $this->sendAt($occurrence, $settings),
            mailClass: $template->mailClass,
            abShare: $template->abShare,
            series: $template->handle,
            sourceKey: 'occurrence:'.$occurrence->uuid,
            meta: $this->childMeta($template, $occurrence, $settings),
        );
    }

    /**
     * Bring an existing child in line with the term as it stands now — the
     * snapshot, the send time, the segment. Never its status, with the one
     * exception below.
     *
     * Returns whether anything moved, so a sync that finds everything already
     * in place counts nothing and writes nothing (not even an updated_at).
     */
    protected function pullChildEven(Campaign $template, Campaign $child, Occurrence $occurrence, array $settings, string $segmentHandle): bool
    {
        $sendAt = $this->sendAt($occurrence, $settings);
        $meta = $this->childMeta($template, $occurrence, $settings);

        // An approved child whose calculated time has passed (approve() sent
        // it "now") keeps what the approval set; only a waiting child follows
        // the calculation down to "no time yet".
        $keepApproval = $sendAt === null && $child->status === Campaign::STATUS_SCHEDULED;

        $scheduledEven = $keepApproval
            || (($child->scheduledAt === null) === ($sendAt === null)
                && ($sendAt === null || $child->scheduledAt->equalTo($sendAt)));

        if (($child->meta['event'] ?? null) === $meta['event']
            && array_values((array) ($child->meta['more_events'] ?? [])) === $meta['more_events']
            && $child->segmentHandle === $segmentHandle
            && $scheduledEven) {
            return false;
        }

        $child->meta = $meta;
        $child->segmentHandle = $segmentHandle;

        if (! $keepApproval) {
            $child->scheduledAt = $sendAt;
        }

        $this->campaigns->save($child);

        return true;
    }

    /**
     * When the child is meant to go out: `send_time` in the term's own zone,
     * N days before it starts. Null once that instant has passed — approval
     * then sends immediately (`approve()` takes max(scheduledAt, now)).
     */
    protected function sendAt(Occurrence $occurrence, array $settings): ?CarbonImmutable
    {
        if ($settings['anchor'] === self::ANCHOR_PRESALE) {
            $presale = $this->presaleStart($occurrence);

            if ($presale === null) {
                return null;
            }

            // The presale day (+ N days) at the send time — but never before
            // the box office opens: "Der Vorverkauf hat gestartet" sent at
            // ten for a presale that opens at noon would be a lie for two
            // hours.
            $local = $presale->setTimezone($occurrence->localStart()->getTimezone());
            $send = $local->addDays((int) $settings['days_after_presale'])
                ->setTimeFromTimeString((string) $settings['send_time']);

            if ($send->lessThan($local)) {
                $send = $local;
            }
        } else {
            $send = $occurrence->localStart()
                ->subDays((int) $settings['days_before'])
                ->setTimeFromTimeString((string) $settings['send_time']);
        }

        if ($send->lessThanOrEqualTo(CarbonImmutable::now())) {
            return null;
        }

        return $send->utc();
    }

    /**
     * The term's presale start, or null — also on a statamic-events without
     * the column (before 2.7), where the attribute simply is not there.
     */
    protected function presaleStart(Occurrence $occurrence): ?CarbonImmutable
    {
        $value = $occurrence->getAttribute('presale_starts_at');

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');
    }

    /**
     * `meta` of a child: its own term, and the later terms nearby.
     *
     * @return array{event: array<string, string>, more_events: list<array<string, string>>}
     */
    protected function childMeta(Campaign $template, Occurrence $occurrence, array $settings): array
    {
        return [
            'event' => $this->eventMeta($occurrence, $settings),
            'more_events' => $this->moreEvents($template, $occurrence, $settings),
        ];
    }

    /**
     * "Weitere Konzerte in deiner Nähe": later, not cancelled, publicly
     * readable terms the template wants, whose venue is at most
     * `more_radius_km` from this one, soonest first, at most `more_limit`.
     *
     * Display only — who receives the mail is still the circle around the
     * main term. The distance is LeadHub's own (`PostalCode::distanceKm`)
     * between the venues' coordinates, from the term where it carries them
     * and from the postal code register where it does not. A venue that has
     * neither cannot be placed and is left out rather than guessed.
     *
     * @return list<array<string, string>>
     */
    protected function moreEvents(Campaign $template, Occurrence $occurrence, array $settings): array
    {
        if (! $settings['more_enabled'] || $settings['more_limit'] < 1) {
            return [];
        }

        $origin = $this->coordinates($occurrence, $settings);

        if ($origin === null) {
            return [];
        }

        $this->upcoming ??= EventsFacade::occurrences(['upcoming' => true, 'include_cancelled' => false]);

        return $this->upcoming
            ->filter(function (Occurrence $other) use ($occurrence, $template, $settings, $origin): bool {
                if ($other->uuid === $occurrence->uuid
                    || $other->isCancelled()
                    || ! $other->starts_at->greaterThan($occurrence->starts_at)
                    || ! $other->event?->isPubliclyReadable()
                    || ! $this->wantsEvent($template, $other)) {
                    return false;
                }

                $point = $this->coordinates($other, $settings);

                return $point !== null
                    && PostalCode::distanceKm($origin[0], $origin[1], $point[0], $point[1]) <= $settings['more_radius_km'];
            })
            ->sortBy(fn (Occurrence $other): int => $other->starts_at->getTimestamp())
            ->take($settings['more_limit'])
            ->map(fn (Occurrence $other): array => $this->eventMeta($other, $settings))
            ->values()
            ->all();
    }

    /**
     * Latitude and longitude of a venue: the term's own when it has them,
     * the postal code's centre otherwise.
     *
     * @return array{0: float, 1: float}|null
     */
    protected function coordinates(Occurrence $occurrence, array $settings): ?array
    {
        $lat = $occurrence->getAttribute('venue_latitude');
        $lng = $occurrence->getAttribute('venue_longitude');

        if (is_numeric($lat) && is_numeric($lng)) {
            return [(float) $lat, (float) $lng];
        }

        if (blank($occurrence->venue_postal_code)) {
            return null;
        }

        $code = PostalCode::lookup(
            (string) $occurrence->venue_postal_code,
            (string) ($occurrence->venue_country ?: $settings['country']),
        );

        return $code ? [(float) $code->getAttribute('latitude'), (float) $code->getAttribute('longitude')] : null;
    }

    /**
     * The moment snapshot `{{ event:… }}` renders. Local date and time, so a
     * template writer reads what a reader reads; `starts_at` carries the
     * offset, so anything doing arithmetic still can.
     *
     * @return array<string, string>
     */
    protected function eventMeta(Occurrence $occurrence, array $settings): array
    {
        $local = $occurrence->localStart();
        $presale = $this->presaleStart($occurrence)?->setTimezone($local->getTimezone());

        return [
            'title' => (string) $occurrence->event?->title,
            'city' => (string) $occurrence->venue_city,
            'venue' => (string) $occurrence->venue_name,
            'street' => (string) $occurrence->getAttribute('venue_address'),
            'postal_code' => (string) $occurrence->venue_postal_code,
            'country' => (string) ($occurrence->venue_country ?: $settings['country']),
            'starts_at' => $local->toIso8601String(),
            'date' => $local->format('d.m.Y'),
            // As the ANDERS mails write it: "Samstag, den 17.10.26 um 20 Uhr".
            // German on purpose and not through the translator: this is what
            // the mail says, whatever language the Control Panel speaks.
            'weekday' => self::WEEKDAYS[(int) $local->format('N') - 1],
            'date_short' => $local->format('d.m.y'),
            'time' => $local->format('H:i'),
            'time_label' => $local->format('i') === '00' ? $local->format('G').' Uhr' : $local->format('G:i').' Uhr',
            'tickets_url' => (string) $occurrence->tickets_url,
            // The link of the term. statamic-events has no public detail URL,
            // so this is the online URL where there is one and empty where
            // there is none — a made-up route would be worse than a blank.
            'url' => (string) $occurrence->online_url,
            'presale_starts_at' => $presale?->toIso8601String() ?? '',
            'presale_date' => $presale?->format('d.m.Y') ?? '',
        ];
    }

    /**
     * The segment for one term, created or pulled even: „Konzert: <Stadt>
     * <PLZ> (<km> km)", handle from template handle and occurrence UUID —
     * one segment per template and term, because two templates may draw
     * different radii around the same venue.
     */
    protected function ensureSegment(Campaign $template, Occurrence $occurrence, array $settings): string
    {
        $handle = $this->segmentHandle($template, $occurrence);
        $rules = [
            'match' => 'all',
            'conditions' => [[
                'type' => 'geo',
                'operator' => 'within_km',
                'plz' => (string) $occurrence->venue_postal_code,
                'value' => (int) $settings['radius_km'],
                'country' => (string) ($occurrence->venue_country ?: $settings['country']),
            ]],
        ];
        // A concert series and a presale series around the same date are two
        // segments; the name says which one a LeadHub user is looking at.
        $name = sprintf(
            '%s: %s %s (%d km)',
            $settings['anchor'] === self::ANCHOR_PRESALE ? 'Vorverkauf' : 'Konzert',
            $occurrence->venue_city,
            $occurrence->venue_postal_code,
            (int) $settings['radius_km'],
        );

        $existing = $this->segments->findByHandle($handle);
        $managedBy = $this->managedBy($template);

        if ($existing === null) {
            try {
                $this->segments->create(['name' => $name, 'handle' => $handle, 'rules' => $rules] + $managedBy);

                return $handle;
            } catch (Throwable $e) {
                // The listener and the night run may race to the same
                // handle; the loser finds what the winner wrote. Anything
                // else (no row afterwards) is a real failure.
                $existing = $this->segments->findByHandle($handle);

                if ($existing === null) {
                    throw $e;
                }
            }
        }

        // Pull the rule even when only the radius changed — a segment that
        // still matches the old circle is the wrong circle. Read through
        // getAttribute: the sibling's model declares the cast, not the
        // property, and the shape lives here rather than in a baseline entry.
        $managedEven = $managedBy === []
            || $existing->getAttribute('managed_by') == $managedBy['managed_by'];

        if ((array) $existing->getAttribute('rules') != $rules
            || (string) $existing->getAttribute('name') !== $name
            || ! $managedEven) {
            $this->segments->update($existing, ['name' => $name, 'rules' => $rules] + $managedBy);
        }

        return $handle;
    }

    /**
     * The `managed_by` mark LeadHub (2.15+) shows on a series segment and
     * locks its rule with — or nothing, on a LeadHub that does not know the
     * field, so the create/update carries no key its table lacks. A missing
     * mark costs a badge; a failed sync would cost the mail.
     *
     * @return array{managed_by?: array{source: string, label: string, url: string|null}}
     */
    protected function managedBy(Campaign $template): array
    {
        // Answered by the installed LeadHub, not by the one PHPStan reads:
        // against 2.15 this is always true, against 2.14 always false.
        // @phpstan-ignore function.alreadyNarrowedType
        $this->managedBySupported ??= method_exists(Segment::class, 'managedBy')
            && (config('leadhub.storage.driver') !== 'eloquent'
                || Schema::hasColumn('leadhub_segments', 'managed_by'));

        if (! $this->managedBySupported) {
            return [];
        }

        return ['managed_by' => [
            'source' => 'statamic-marketing',
            'label' => 'Serie „'.$template->name.'“',
            'url' => Route::has('statamic.cp.marketing.campaigns.show')
                ? cp_route('marketing.campaigns.show', $template->handle)
                : null,
        ]];
    }

    /**
     * Children whose template or term is gone, and nothing else. Occurrences
     * are asked directly (not through the facade): whether a child may be
     * removed must not depend on the event still being published.
     *
     * @param  Collection<int, Campaign>  $templates
     * @param  Collection<int, Campaign>  $all
     * @param  array{created: int, updated: int, removed: int, skipped_no_postal_code: int, skipped_no_presale: int}  $result
     */
    protected function cleanUpOrphans(Collection $templates, Collection $all, array &$result): void
    {
        $children = $all->filter(
            fn (Campaign $campaign): bool => $campaign->series !== null
                && $campaign->sourceKey !== null
                && ! in_array($campaign->status, [Campaign::STATUS_SENDING, Campaign::STATUS_SENT], true)
        );

        if ($children->isEmpty()) {
            return;
        }

        $templateHandles = $templates->map(fn (Campaign $campaign): string => $campaign->handle)->all();

        // Which terms are still standing, as scheduled dates.
        $uuids = $children
            ->map(fn (Campaign $campaign): string => (string) substr((string) $campaign->sourceKey, strlen('occurrence:')))
            ->all();

        $alive = Occurrence::query()
            ->with('event')
            ->whereIn('uuid', $uuids)
            ->scheduled()
            ->get()
            ->keyBy('uuid');

        $templatesByHandle = $templates->keyBy(fn (Campaign $campaign): string => $campaign->handle);

        foreach ($children as $child) {
            $uuid = (string) substr((string) $child->sourceKey, strlen('occurrence:'));
            $occurrence = $alive->get($uuid);
            $template = $templatesByHandle->get($child->series);

            // Kept only while the term stands, its event is still public and
            // the template still names that event.
            if ($occurrence !== null
                && $template !== null
                && in_array($child->series, $templateHandles, true)
                && $occurrence->event?->isPubliclyReadable()
                && $this->wantsEvent($template, $occurrence)) {
                continue;
            }

            $this->campaigns->delete($child->handle);
            $this->deleteSegmentIfUnused($child->segmentHandle);
            $result['removed']++;
        }
    }

    /**
     * Delete the unsent children of one term (across every template) — the
     * answer to a cancelled date, and to a deleted one on the daily run.
     *
     * @param  Collection<int, Campaign>|null  $all  the campaigns, when the caller already holds them
     */
    protected function removeForOccurrence(string $uuid, ?Collection $all = null): int
    {
        $removed = 0;
        $all ??= $this->campaigns->all();
        $sourceKey = 'occurrence:'.$uuid;

        foreach ($all as $campaign) {
            if ($campaign->sourceKey !== $sourceKey
                || in_array($campaign->status, [Campaign::STATUS_SENDING, Campaign::STATUS_SENT], true)) {
                continue;
            }

            $this->campaigns->delete($campaign->handle);
            $this->deleteSegmentIfUnused($campaign->segmentHandle);
            $removed++;
        }

        if ($removed > 0) {
            Log::info("Marketing series: removed {$removed} campaign(s) for occurrence [{$uuid}].");
        }

        return $removed;
    }

    /**
     * A segment goes when the last campaign that aimed at it is gone. Another
     * template's child of the same term still holds the handle, and taking
     * the segment from under it would leave a campaign mailing nobody.
     */
    protected function deleteSegmentIfUnused(?string $segmentHandle): void
    {
        if (! $segmentHandle) {
            return;
        }

        // Asked fresh, after the campaign above was deleted, so the deleted
        // row itself cannot keep its own segment alive.
        $stillUsed = $this->campaigns->all()
            ->contains(fn (Campaign $campaign): bool => $campaign->segmentHandle === $segmentHandle);

        if ($stillUsed) {
            return;
        }

        $segment = $this->segments->findByHandle($segmentHandle);
        $segment?->delete();
    }

    /** Does this template want this term? Empty `event_ids` = every event. */
    protected function wantsEvent(Campaign $template, Occurrence $occurrence): bool
    {
        $event = $occurrence->event;

        if ($event === null) {
            return false;
        }

        $ids = $this->settings($template)['event_ids'];

        return $ids === [] || in_array($event->uuid, $ids, true);
    }

    /**
     * The template's series settings, normalised against the defaults: a key
     * the editor never filled answers with its Vorgabe, not with null.
     *
     * @return array{radius_km: int, days_before: int, send_time: string, event_ids: list<string>, country: string, anchor: string, days_after_presale: int, more_enabled: bool, more_radius_km: int, more_limit: int}
     */
    protected function settings(Campaign $template): array
    {
        $stored = (array) ($template->meta['series'] ?? []);

        return [
            'radius_km' => max(1, (int) ($stored['radius_km'] ?? self::DEFAULT_SETTINGS['radius_km'])),
            'days_before' => max(0, (int) ($stored['days_before'] ?? self::DEFAULT_SETTINGS['days_before'])),
            'send_time' => (string) ($stored['send_time'] ?? self::DEFAULT_SETTINGS['send_time']),
            'event_ids' => array_values((array) ($stored['event_ids'] ?? [])),
            'country' => (string) ($stored['country'] ?? self::DEFAULT_SETTINGS['country']),
            'anchor' => ($stored['anchor'] ?? null) === self::ANCHOR_PRESALE ? self::ANCHOR_PRESALE : self::ANCHOR_CONCERT,
            'days_after_presale' => max(0, (int) ($stored['days_after_presale'] ?? self::DEFAULT_SETTINGS['days_after_presale'])),
            'more_enabled' => (bool) ($stored['more_enabled'] ?? self::DEFAULT_SETTINGS['more_enabled']),
            'more_radius_km' => max(1, (int) ($stored['more_radius_km'] ?? self::DEFAULT_SETTINGS['more_radius_km'])),
            'more_limit' => max(0, (int) ($stored['more_limit'] ?? self::DEFAULT_SETTINGS['more_limit'])),
        ];
    }

    /** Deterministic, so the second run finds the first run's child. */
    protected function childHandle(Campaign $template, Occurrence $occurrence): string
    {
        return $template->handle.'-'.$occurrence->uuid;
    }

    /**
     * The segment's handle: `series-` + the child's handle. The leadhub
     * column is a plain unique string(255); template handles are at most 100
     * characters of [a-z0-9_] and a UUID adds 37, so this always fits.
     */
    protected function segmentHandle(Campaign $template, Occurrence $occurrence): string
    {
        return 'series-'.$this->childHandle($template, $occurrence);
    }

    /**
     * Delete one unsent child and its segment when nothing else uses it.
     */
    protected function removeChild(string $handle): bool
    {
        $child = $this->campaigns->find($handle);

        if (! $child || in_array($child->status, [Campaign::STATUS_SENDING, Campaign::STATUS_SENT], true)) {
            return false;
        }

        $this->campaigns->delete($child->handle);
        $this->deleteSegmentIfUnused($child->segmentHandle);

        return true;
    }

    /**
     * The template's settings as the sync reads them — what the CP form
     * shows, so the screen and the sync cannot disagree about a default.
     *
     * @return array{radius_km: int, days_before: int, send_time: string, event_ids: list<string>, country: string, anchor: string, days_after_presale: int, more_enabled: bool, more_radius_km: int, more_limit: int}
     */
    public function settingsFor(Campaign $template): array
    {
        return $this->settings($template);
    }

    /**
     * The campaigns this template has produced, soonest term first.
     *
     * @return Collection<int, Campaign>
     */
    public function childrenOf(Campaign $template): Collection
    {
        return $this->campaigns->all()
            ->filter(fn (Campaign $campaign): bool => $campaign->series === $template->handle)
            ->sortBy(fn (Campaign $campaign): string => (string) ($campaign->meta['event']['starts_at'] ?? ''))
            ->values();
    }

    /**
     * How many upcoming terms this template would want but cannot reach,
     * because the venue has no postal code to draw the circle around.
     *
     * Asked on its own rather than read off the last sync result: the
     * editor opens the screen days after the save, and a notice that only
     * appears in the flash right after saving is one nobody sees twice.
     */
    public function missingPostalCodes(Campaign $template): int
    {
        if (! static::available() || ! $template->isSeries()) {
            return 0;
        }

        return EventsFacade::occurrences(['upcoming' => true, 'include_cancelled' => false])
            ->filter(fn (Occurrence $occurrence): bool => (bool) $occurrence->event?->isPubliclyReadable()
                && $this->wantsEvent($template, $occurrence)
                && blank($occurrence->venue_postal_code))
            ->count();
    }

    /**
     * How many upcoming terms a presale template would want but cannot time,
     * because they carry no presale date (or the installed statamic-events
     * has no such field yet). Zero for a concert-anchored template.
     */
    public function missingPresale(Campaign $template): int
    {
        if (! static::available() || ! $template->isSeries()
            || $this->settings($template)['anchor'] !== self::ANCHOR_PRESALE) {
            return 0;
        }

        return EventsFacade::occurrences(['upcoming' => true, 'include_cancelled' => false])
            ->filter(fn (Occurrence $occurrence): bool => (bool) $occurrence->event?->isPubliclyReadable()
                && $this->wantsEvent($template, $occurrence)
                && ! blank($occurrence->venue_postal_code)
                && $this->presaleStart($occurrence) === null)
            ->count();
    }

    /** Does the installed statamic-events carry a presale date at all? */
    public static function presaleSupported(): bool
    {
        return static::available() && Schema::hasColumn('event_occurrences', 'presale_starts_at');
    }

    /**
     * The events a template may be narrowed to, for the CP picker.
     *
     * @return list<array{value: string, label: string}>
     */
    public function eventOptions(): array
    {
        if (! static::available()) {
            return [];
        }

        return EventsFacade::events()
            ->map(fn ($event): array => ['value' => (string) $event->uuid, 'label' => (string) $event->title])
            ->values()
            ->all();
    }

    /**
     * Remove the children whose template or term is gone — what a template
     * delete in the CP asks for right away instead of waiting for the night.
     *
     * @return int how many children went
     */
    public function cleanUp(): int
    {
        if (! static::available()) {
            return 0;
        }

        $all = $this->campaigns->all();
        $result = self::EMPTY_RESULT;

        $this->cleanUpOrphans($this->templatesOf($all), $all, $result);

        return $result['removed'];
    }

    /** @return Collection<int, Campaign> */
    protected function templates(): Collection
    {
        return $this->templatesOf($this->campaigns->all());
    }

    /** @param  Collection<int, Campaign>  $all */
    protected function templatesOf(Collection $all): Collection
    {
        return $all->filter(fn (Campaign $campaign): bool => $campaign->isSeries());
    }

    /**
     * Count one syncOne outcome into the tally.
     *
     * @param  array<string, int>  $result
     */
    protected function tallyInto(array &$result, string $outcome): void
    {
        if ($outcome !== 'untouched') {
            $result[$outcome]++;
        }
    }

    /**
     * @param  array{created: int, updated: int, removed: int, skipped_no_postal_code: int, skipped_no_presale: int}  $result
     * @param  array{created: int, updated: int, removed: int, skipped_no_postal_code: int, skipped_no_presale: int}  $partial
     */
    protected function merge(array &$result, array $partial): void
    {
        foreach ($partial as $key => $count) {
            $result[$key] += $count;
        }
    }
}
