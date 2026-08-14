<?php

namespace Goldnead\Marketing\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Support\ContactLinks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * "What happened to this campaign, and with whom."
 *
 * {@see CampaignStats} answers the first half and is deliberately untouched by
 * anything here: its figures are what every earlier campaign was measured with,
 * and redefining one of them quietly would look like a collapse in engagement
 * that never happened. This class answers the second half — the people behind
 * each number — and adds the figures that are new rather than restated.
 *
 * Three rules run through all of it:
 *
 *  - **Every list is paginated and eager-loaded.** A campaign can have fifty
 *    thousand recipients. The number of queries a page costs does not depend on
 *    how many rows are on it, and CampaignReportQueryCountTest holds that.
 *  - **Events reach messages through a sub-select**, never through a join or a
 *    pluck of ids. It is the established pattern (CampaignStats::metrics()) and
 *    it is what keeps the brand scope on both sides of the hop.
 *  - **The person is the address**, frozen on the message at the moment of
 *    sending. A subscription that has since been deleted removes a name, never
 *    a row.
 */
class CampaignReport
{
    public const TAB_OVERVIEW = 'overview';

    public const TAB_DELIVERY = 'delivery';

    public const TAB_OPENS = 'opens';

    public const TAB_CLICKS = 'clicks';

    public const TAB_UNSUBSCRIBES = 'unsubscribes';

    /** @var list<string> */
    public const TABS = [
        self::TAB_OVERVIEW,
        self::TAB_DELIVERY,
        self::TAB_OPENS,
        self::TAB_CLICKS,
        self::TAB_UNSUBSCRIBES,
    ];

    /** The rest of the addon paginates at 50. A report is not the place to differ. */
    public const PER_PAGE = 50;

    /** The message statuses the delivery tab can be narrowed to. */
    public const STATUSES = [
        Message::STATUS_PENDING,
        Message::STATUS_SENT,
        Message::STATUS_FAILED,
        Message::STATUS_SKIPPED,
        Message::STATUS_BOUNCED,
        Message::STATUS_CAPPED,
    ];

    public function __construct(protected ContactLinks $contacts) {}

    /** A tab name from the query string, or the one the report opens on. */
    public function tab(?string $requested): string
    {
        return in_array($requested, self::TABS, true) ? $requested : self::TAB_OVERVIEW;
    }

    /** A status filter from the query string, or null for "all of them". */
    public function status(?string $requested): ?string
    {
        return in_array($requested, self::STATUSES, true) ? $requested : null;
    }

    /**
     * The columns of one person tab, in order: field key => column label.
     *
     * One declaration, three consumers — the `Statamic\CP\Column` list the
     * Listing renders, the keys the row builders write, and the CSV header. A
     * tab and its export cannot drift into different columns because there is
     * only one place that says what the columns are.
     *
     * @param  bool  $withErrors  whether the delivery tab shows its `error`
     *                            column. False on a campaign where nothing
     *                            failed: a column of dashes on every report of
     *                            every install that never fails is noise, and
     *                            the addon already hides the "Capped" tile on
     *                            the same reasoning. The CSV ignores this and
     *                            always carries the field — a file is read by
     *                            a program, and a column that appears and
     *                            disappears is worse there than an empty one.
     * @return array<string, string>
     */
    public function fields(string $tab, bool $withErrors = true): array
    {
        $person = [
            'email' => __('marketing::subscribers.email'),
            'name' => __('marketing::subscribers.name'),
        ];

        return match ($tab) {
            self::TAB_DELIVERY => $person + [
                'status' => __('marketing::campaigns.status'),
                'sent_at' => __('marketing::campaigns.sent_at'),
                'opens' => __('marketing::campaigns.opens'),
                'clicks' => __('marketing::campaigns.clicks'),
            ] + ($withErrors ? ['error' => __('marketing::campaigns.report.error')] : []),

            self::TAB_OPENS => $person + [
                'first_opened_at' => __('marketing::campaigns.report.first_opened_at'),
                'last_opened_at' => __('marketing::campaigns.report.last_opened_at'),
                'opens' => __('marketing::campaigns.opens'),
                'human_opens' => __('marketing::campaigns.report.human_opens'),
                'machine_opens' => __('marketing::campaigns.report.machine_opens'),
            ],

            self::TAB_CLICKS => $person + [
                'clicked_at' => __('marketing::campaigns.report.clicked_at'),
                'url' => __('marketing::campaigns.report.url'),
            ],

            self::TAB_UNSUBSCRIBES => $person + [
                'unsubscribed_at' => __('marketing::campaigns.report.unsubscribed_at'),
            ],

            default => [],
        };
    }

    // -- Queries ------------------------------------------------------------
    //
    // Each one is handed to the page and to the CSV export unchanged, which is
    // how the two are guaranteed to contain the same rows in the same order.

    /** @return Builder<Message> */
    public function deliveryQuery(string $handle, ?string $status = null): Builder
    {
        return Message::forCampaign($handle)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with('subscription')
            ->orderByDesc('id');
    }

    /**
     * Who opened, in the order they first did — newest first.
     *
     * Built on messages rather than on open events, because the question is
     * per person: one reader who opened a mail six times is one row with a six
     * in it, not six rows.
     *
     * @return Builder<Message>
     */
    public function opensQuery(string $handle): Builder
    {
        // `first_opened_at`, not `opens > 0`. A click sets the timestamp
        // without touching the counter (TrackingService::recordClick), so a
        // reader whose client blocks images and who clicked has `opens = 0` —
        // and was dropped from a tab whose first column is that very
        // timestamp. The same value feeds the "first open" station on the
        // overview, which could therefore name a moment at which this tab
        // showed nobody.
        return Message::forCampaign($handle)
            ->whereNotNull('first_opened_at')
            ->with('subscription')
            ->orderByDesc('first_opened_at')
            ->orderByDesc('id');
    }

    /**
     * Every click, one row each.
     *
     * The one tab that is not per person on purpose: a reader who followed two
     * different links did two different things, and a report that collapsed
     * them would lose the half of the question that says *which* link.
     *
     * @return Builder<MessageEvent>
     */
    public function clicksQuery(string $handle): Builder
    {
        return $this->eventQuery($handle, MessageEvent::TYPE_CLICK);
    }

    /** @return Builder<MessageEvent> */
    public function unsubscribesQuery(string $handle): Builder
    {
        return $this->eventQuery($handle, MessageEvent::TYPE_UNSUBSCRIBE);
    }

    /** @return Builder<MessageEvent> */
    protected function eventQuery(string $handle, string $type): Builder
    {
        return MessageEvent::query()
            ->where('type', $type)
            // The sub-select, not a pluck: the ids of a campaign with fifty
            // thousand recipients are not something to carry through PHP, and
            // the brand scope rides along on both halves.
            ->whereIn('message_id', Message::forCampaign($handle)->select('id'))
            ->with('message.subscription')
            ->orderByDesc('id');
    }

    /**
     * The query behind one person tab.
     *
     * The screen and the CSV both come through here, which is what makes the
     * export "the same rows in the same order" rather than a second
     * implementation that happens to agree today.
     *
     * @return Builder<Message>|Builder<MessageEvent>
     */
    public function queryFor(string $tab, string $handle, ?string $status = null): Builder
    {
        return match ($tab) {
            self::TAB_DELIVERY => $this->deliveryQuery($handle, $status),
            self::TAB_OPENS => $this->opensQuery($handle),
            self::TAB_CLICKS => $this->clicksQuery($handle),
            self::TAB_UNSUBSCRIBES => $this->unsubscribesQuery($handle),
            default => throw new InvalidArgumentException("[{$tab}] is not a tab with rows."),
        };
    }

    /**
     * @param  EloquentCollection<int, Message>|EloquentCollection<int, MessageEvent>|Collection<int, mixed>  $models
     * @return list<array<string, mixed>>
     */
    public function rowsFor(string $tab, $models): array
    {
        return match ($tab) {
            self::TAB_DELIVERY => $this->deliveryRows($models),
            self::TAB_OPENS => $this->openRows($models),
            self::TAB_CLICKS => $this->clickRows($models),
            self::TAB_UNSUBSCRIBES => $this->unsubscribeRows($models),
            default => [],
        };
    }

    // -- Rows ---------------------------------------------------------------

    /**
     * @param  EloquentCollection<int, Message>|Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function deliveryRows($messages): array
    {
        $links = $this->contacts->forEmails(collect($messages)->pluck('email')->all());

        return collect($messages)->map(fn (Message $message) => [
            'id' => $message->uuid,
            'email' => (string) $message->email,
            'name' => $this->nameOf($message),
            'status' => (string) $message->status,
            'sent_at' => $this->iso($message->sent_at),
            'opens' => (int) $message->opens,
            'clicks' => (int) $message->clicks,
            // The column has been in the schema since the first migration and
            // has never been on a screen. "Why did this one fail" was
            // answerable only by opening the database.
            'error' => $message->error === null ? null : (string) $message->error,
            'contact_url' => $this->contacts->urlFor($message->email, $links),
        ])->values()->all();
    }

    /**
     * @param  EloquentCollection<int, Message>|Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function openRows($messages): array
    {
        $messages = collect($messages);
        $links = $this->contacts->forEmails($messages->pluck('email')->all());
        $split = $this->openSplit($messages->pluck('id')->all());

        return $messages->map(function (Message $message) use ($links, $split) {
            $counts = $split[$message->id] ?? ['human' => 0, 'machine' => 0];

            return [
                'id' => $message->uuid,
                'email' => (string) $message->email,
                'name' => $this->nameOf($message),
                'first_opened_at' => $this->iso($message->first_opened_at),
                'last_opened_at' => $this->iso($message->last_opened_at),
                'opens' => (int) $message->opens,
                'human_opens' => $counts['human'],
                'machine_opens' => $counts['machine'],
                'contact_url' => $this->contacts->urlFor($message->email, $links),
            ];
        })->values()->all();
    }

    /**
     * @param  EloquentCollection<int, MessageEvent>|Collection<int, MessageEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function clickRows($events): array
    {
        return $this->eventRows($events, fn (MessageEvent $event) => [
            'clicked_at' => $this->iso($event->created_at),
            'url' => $event->url === null ? null : (string) $event->url,
        ]);
    }

    /**
     * @param  EloquentCollection<int, MessageEvent>|Collection<int, MessageEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function unsubscribeRows($events): array
    {
        return $this->eventRows($events, fn (MessageEvent $event) => [
            'unsubscribed_at' => $this->iso($event->created_at),
        ]);
    }

    /**
     * @param  EloquentCollection<int, MessageEvent>|Collection<int, MessageEvent>  $events
     * @param  callable(MessageEvent): array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    protected function eventRows($events, callable $extra): array
    {
        $events = collect($events);
        $links = $this->contacts->forEmails(
            $events->map(fn (MessageEvent $event) => $event->message?->email)->all()
        );

        return $events->map(function (MessageEvent $event) use ($links, $extra) {
            // `->` rather than `?->`: `??` already has isset() semantics, so a
            // deleted message reads as '' without the nullsafe operator.
            $email = (string) ($event->message->email ?? '');

            return [
                'id' => (string) $event->id,
                'email' => $email,
                'name' => $event->message ? $this->nameOf($event->message) : null,
                ...$extra($event),
                'contact_url' => $this->contacts->urlFor($email, $links),
            ];
        })->values()->all();
    }

    // -- Overview -----------------------------------------------------------

    /**
     * The figures this report adds, next to the ones CampaignStats already
     * gives — never instead of them.
     *
     * `opened` over there counts every message whose pixel was fetched, machine
     * or person, and it keeps doing that. What is missing from it is the
     * distinction Apple's Mail Privacy Protection made necessary: MPP loads the
     * image of every message it delivers, whether anybody looks or not, and
     * then caches it, so a naive count says "opened" about a mail nobody read
     * and stays silent about the one they did.
     *
     * So three numbers, all exact and none of them a redefinition:
     *
     *  - `human` — messages with at least one open a person is believed to have
     *    made. This is the number to read.
     *  - `machine_only` — messages that were opened and never by a recognised
     *    person, and never clicked. `opened` minus `human`, and therefore the
     *    part of `opened` that does not mean somebody read anything.
     *  - `human_rate` — `human` against delivered, the same denominator the
     *    existing open rate uses, so the two are comparable at a glance.
     *
     * @param  array<string, mixed>  $stats  the figures from {@see CampaignStats}
     * @return array{human:int, machine_only:int, human_rate:float}
     */
    public function humanOpens(string $handle, array $stats): array
    {
        $messages = Message::forCampaign($handle)->select('id');

        // A click counts as a person, and it has to: under Apple's Mail
        // Privacy Protection the proxy fetches the pixel for every delivered
        // message, so the only open on record is the machine's. Counting opens
        // alone then reports "read by nobody" for a campaign somebody clicked
        // through — while the note printed directly beneath says a click is
        // exactly what proves a person was there.
        //
        // A click writes no open event and does not touch the `opens` counter
        // (see TrackingService::recordClick), so it has to be asked for
        // separately. `distinct message_id` keeps a person who did both from
        // counting twice.
        $human = MessageEvent::query()
            ->whereIn('message_id', $messages)
            ->where(function ($query): void {
                $query->where(function ($open): void {
                    $open->where('type', MessageEvent::TYPE_OPEN)->where('machine', false);
                })->orWhere('type', MessageEvent::TYPE_CLICK);
            })
            ->distinct()
            ->count('message_id');

        $sent = (int) ($stats['sent'] ?? 0);
        $opened = (int) ($stats['opened'] ?? 0);

        return [
            'human' => $human,
            // Clamped, and it now genuinely can go negative: somebody whose
            // client blocks images and who clicked is a human with no open at
            // all, so `human` may exceed `opened`. That is not an error, it is
            // the point — but "machine only" must not become a negative number
            // on the way there.
            'machine_only' => max(0, $opened - $human),
            'human_rate' => $sent > 0 ? round($human / $sent * 100, 1) : 0.0,
        ];
    }

    /**
     * The campaign as a sequence: planned, started, sent, first read, last sign
     * of life.
     *
     * A station that did not happen is left out rather than shown empty. A
     * campaign that was never scheduled has no scheduling to report, and a row
     * saying "Geplant: —" is an invitation to wonder what went wrong.
     *
     * Two aggregate queries, regardless of how many recipients there are.
     *
     * @return list<array{key:string, at:string}>
     */
    public function timeline(Campaign $campaign): array
    {
        $aggregate = Message::forCampaign($campaign->handle)
            ->toBase()
            ->selectRaw('MIN(created_at) as started_at, MAX(sent_at) as last_sent_at, MIN(first_opened_at) as first_opened_at')
            ->first();

        $lastActivity = MessageEvent::query()
            ->whereIn('message_id', Message::forCampaign($campaign->handle)->select('id'))
            ->max('created_at');

        $stations = [
            // From the campaign file, not from the messages: a scheduled
            // campaign has no messages yet, and that is exactly when somebody
            // opens this to check the date.
            'scheduled' => $campaign->scheduledAt,
            // When the audience snapshot was written — the first thing the send
            // does, and the only record of when it began.
            'started' => $aggregate?->started_at,
            'sent' => $campaign->sentAt ?? $aggregate?->last_sent_at,
            'first_open' => $aggregate?->first_opened_at,
            'last_activity' => $lastActivity,
        ];

        // `started` is the only derived station: nothing records when a send
        // began, so the audience snapshot stands in for it. When that stand-in
        // lands after the send finished, the derivation is wrong for this data
        // — messages written after the fact, an import, a re-run — and the
        // timeline would read "sent, then started". Drop the claim rather than
        // print an impossible order; the stations it stands between are both
        // recorded facts and survive on their own.
        if ($stations['started'] && $stations['sent']
            && $this->at($stations['started'])?->greaterThan($this->at($stations['sent']))) {
            $stations['started'] = null;
        }

        return collect($stations)
            ->map(fn ($value) => $this->iso($value))
            ->filter()
            ->map(fn (string $at, string $key) => ['key' => $key, 'at' => $at])
            ->values()
            ->all();
    }

    /**
     * Which link was followed how often, and by how many people.
     *
     * Not paginated, and that is a statement about the data rather than an
     * oversight: the set of distinct URLs in one campaign is the set of links
     * in one e-mail. The tracked target is the destination as it stood in the
     * HTML (see CampaignRenderer), identical for every recipient, so this
     * groups to a handful of rows even for a campaign with fifty thousand of
     * them.
     *
     * @return list<array{url:string, clicks:int, people:int}>
     */
    public function urlBreakdown(string $handle): array
    {
        return MessageEvent::query()
            ->where('type', MessageEvent::TYPE_CLICK)
            ->whereIn('message_id', Message::forCampaign($handle)->select('id'))
            ->whereNotNull('url')
            ->toBase()
            ->selectRaw('url, COUNT(*) as clicks, COUNT(DISTINCT message_id) as people')
            ->groupBy('url')
            ->orderByDesc('clicks')
            ->get()
            ->map(fn ($row) => [
                'url' => (string) $row->url,
                'clicks' => (int) $row->clicks,
                'people' => (int) $row->people,
            ])
            ->values()
            ->all();
    }

    /** Does any message of this campaign carry a reason it did not go out? */
    public function hasErrors(string $handle): bool
    {
        return Message::forCampaign($handle)
            ->whereNotNull('error')
            ->where('error', '!=', '')
            ->exists();
    }

    // -- Internals ----------------------------------------------------------

    /**
     * Human and machine opens per message, for the rows on one page.
     *
     * One grouped query for the whole page. Asked per row it would be the N+1
     * this report exists not to have.
     *
     * @param  list<int|string>  $messageIds
     * @return array<int, array{human:int, machine:int}>
     */
    protected function openSplit(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        return MessageEvent::query()
            ->where('type', MessageEvent::TYPE_OPEN)
            ->whereIn('message_id', $messageIds)
            ->toBase()
            ->selectRaw(
                'message_id, '.
                'SUM(CASE WHEN machine = 0 THEN 1 ELSE 0 END) as human_opens, '.
                'SUM(CASE WHEN machine = 1 THEN 1 ELSE 0 END) as machine_opens'
            )
            ->groupBy('message_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->message_id => [
                'human' => (int) $row->human_opens,
                'machine' => (int) $row->machine_opens,
            ]])
            ->all();
    }

    /**
     * The recipient's name, when there is still a subscription holding one.
     *
     * Null rather than a fallback to the address: the address is already the
     * next column, and repeating it as a name would make a deleted subscription
     * look like a person with an e-mail address for a name.
     */
    protected function nameOf(Message $message): ?string
    {
        $subscription = $message->subscription;

        if (! $subscription) {
            return null;
        }

        $name = trim(($subscription->first_name ?? '').' '.($subscription->last_name ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * One station as a comparable instant, whatever shape it arrived in.
     *
     * The aggregate hands back raw driver strings (`toBase()`), the campaign
     * file hands back CarbonImmutable, and the event query hands back either
     * depending on the connection.
     */
    protected function at(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value instanceof \DateTimeInterface ? $value : (string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
