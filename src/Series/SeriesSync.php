<?php

namespace Goldnead\Marketing\Series;

use Carbon\CarbonImmutable;
use Goldnead\Events\Facades\Events as EventsFacade;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
    ];

    /** @var array{created: int, updated: int, removed: int, skipped_no_postal_code: int} */
    public const EMPTY_RESULT = [
        'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped_no_postal_code' => 0,
    ];

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
     * @return array{created: int, updated: int, removed: int, skipped_no_postal_code: int}
     */
    public function syncOccurrence(Occurrence $occurrence): array
    {
        if (! static::available()) {
            return self::EMPTY_RESULT;
        }

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
     * @return array{created: int, updated: int, removed: int, skipped_no_postal_code: int}
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
     * @return array{created: int, updated: int, removed: int, skipped_no_postal_code: int}
     */
    public function syncTemplate(Campaign $template): array
    {
        if (! static::available() || ! $template->isSeries()) {
            return self::EMPTY_RESULT;
        }

        $result = self::EMPTY_RESULT;

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
        if (! $occurrence->event?->isPubliclyReadable()) {
            return 'untouched';
        }

        if (! $this->wantsEvent($template, $occurrence)) {
            return 'untouched';
        }

        if (blank($occurrence->venue_postal_code)) {
            return 'skipped_no_postal_code';
        }

        $child = $this->campaigns->find($this->childHandle($template, $occurrence));

        if ($child && in_array($child->status, [Campaign::STATUS_SENDING, Campaign::STATUS_SENT], true)) {
            return 'untouched';
        }

        $settings = $this->settings($template);
        $segmentHandle = $this->ensureSegment($occurrence, $settings);

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

        return $this->pullChildEven($child, $occurrence, $settings, $segmentHandle) ? 'updated' : 'untouched';
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
            meta: ['event' => $this->eventMeta($occurrence, $settings)],
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
    protected function pullChildEven(Campaign $child, Occurrence $occurrence, array $settings, string $segmentHandle): bool
    {
        $sendAt = $this->sendAt($occurrence, $settings);
        $eventMeta = $this->eventMeta($occurrence, $settings);

        $scheduledEven = ($child->scheduledAt === null) === ($sendAt === null)
            && ($sendAt === null || $child->scheduledAt->equalTo($sendAt));

        if (($child->meta['event'] ?? null) === $eventMeta
            && $child->segmentHandle === $segmentHandle
            && $scheduledEven) {
            return false;
        }

        $child->meta = ['event' => $eventMeta];
        $child->segmentHandle = $segmentHandle;
        $child->scheduledAt = $sendAt;

        // The one status move the sync ever makes on its own: the calculated
        // send time has passed while the term is still upcoming. A child that
        // stayed `scheduled` with a past date would be picked up by
        // marketing:send-scheduled without anybody having approved it — the
        // one thing the waiting state exists to prevent.
        if ($sendAt === null) {
            $child->status = Campaign::STATUS_AWAITING_APPROVAL;
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
        $send = $occurrence->localStart()
            ->subDays((int) $settings['days_before'])
            ->setTimeFromTimeString((string) $settings['send_time']);

        if ($send->lessThanOrEqualTo(CarbonImmutable::now())) {
            return null;
        }

        return $send->utc();
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

        return [
            'title' => (string) $occurrence->event?->title,
            'city' => (string) $occurrence->venue_city,
            'venue' => (string) $occurrence->venue_name,
            'postal_code' => (string) $occurrence->venue_postal_code,
            'country' => (string) ($occurrence->venue_country ?: $settings['country']),
            'starts_at' => $local->toIso8601String(),
            'date' => $local->format('d.m.Y'),
            'time' => $local->format('H:i'),
            'tickets_url' => (string) $occurrence->tickets_url,
            // The link of the term. statamic-events has no public detail URL,
            // so this is the online URL where there is one and empty where
            // there is none — a made-up route would be worse than a blank.
            'url' => (string) $occurrence->online_url,
        ];
    }

    /**
     * The segment for one term, created or pulled even: „Konzert: <Stadt>
     * <PLZ> (<km> km)", handle from the occurrence UUID — one segment per
     * term, shared by every template that aims at it.
     */
    protected function ensureSegment(Occurrence $occurrence, array $settings): string
    {
        $handle = $occurrence->uuid;
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
        $name = sprintf(
            'Konzert: %s %s (%d km)',
            $occurrence->venue_city,
            $occurrence->venue_postal_code,
            (int) $settings['radius_km'],
        );

        $existing = $this->segments->findByHandle($handle);

        if ($existing === null) {
            $this->segments->create(['name' => $name, 'handle' => $handle, 'rules' => $rules]);

            return $handle;
        }

        // Pull the rule even when only the radius changed — a segment that
        // still matches the old circle is the wrong circle. Read through
        // getAttribute: the sibling's model declares the cast, not the
        // property, and the shape lives here rather than in a baseline entry.
        if ((array) $existing->getAttribute('rules') != $rules) {
            $this->segments->update($existing, ['name' => $name, 'rules' => $rules]);
        }

        return $handle;
    }

    /**
     * Children whose template or term is gone, and nothing else. Occurrences
     * are asked directly (not through the facade): whether a child may be
     * removed must not depend on the event still being published.
     *
     * @param  Collection<int, Campaign>  $templates
     * @param  Collection<int, Campaign>  $all
     * @param  array{created: int, updated: int, removed: int, skipped_no_postal_code: int}  $result
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
            ->whereIn('uuid', $uuids)
            ->scheduled()
            ->pluck('uuid')
            ->all();

        foreach ($children as $child) {
            $uuid = (string) substr((string) $child->sourceKey, strlen('occurrence:'));

            if (in_array($uuid, $alive, true) && in_array($child->series, $templateHandles, true)) {
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
     * @return array{radius_km: int, days_before: int, send_time: string, event_ids: list<string>, country: string}
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
        ];
    }

    /** Deterministic, so the second run finds the first run's child. */
    protected function childHandle(Campaign $template, Occurrence $occurrence): string
    {
        return $template->handle.'-'.$occurrence->uuid;
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
     * @param  array{created: int, updated: int, removed: int, skipped_no_postal_code: int}  $result
     * @param  array{created: int, updated: int, removed: int, skipped_no_postal_code: int}  $partial
     */
    protected function merge(array &$result, array $partial): void
    {
        foreach ($partial as $key => $count) {
            $result[$key] += $count;
        }
    }
}
