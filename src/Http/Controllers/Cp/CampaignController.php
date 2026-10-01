<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Carbon\CarbonImmutable;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Contracts\SenderIdentityResolver;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Series\SeriesSync;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\CampaignReport;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Support\CampaignContentField;
use Goldnead\Marketing\Support\EmailTemplateOptions;
use Goldnead\Marketing\Support\HandleOwnership;
use Goldnead\Marketing\Support\SendSnapshot;
use Goldnead\Marketing\Support\Setup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Statamic\CP\Column;
use Statamic\Facades\Entry;
use Statamic\Support\Str;

class CampaignController extends Controller
{
    public function __construct(
        protected CampaignRepository $campaigns,
        protected MailingListRepository $lists,
        protected EmailTemplateRepository $templates,
    ) {}

    public function index(Request $request, CampaignStats $stats)
    {
        $this->authorizeOrFail($request, 'view marketing');

        // Recipients and the open rate per row: the messages, and the events
        // behind the unsubscribe figure `CampaignStats` folds into them.
        if ($setup = Setup::guard(
            __('marketing::nav.campaigns'),
            'marketing_messages',
            'marketing_message_events',
            ...Setup::definitionTables('marketing_campaigns'),
        )) {
            return $setup;
        }

        $all = $this->campaigns->all();

        // How many campaigns each series template has produced, counted once
        // over the list already in hand rather than once per row.
        $childCounts = $all->filter(fn (Campaign $campaign): bool => $campaign->series !== null)
            ->countBy(fn (Campaign $campaign): string => (string) $campaign->series);

        // Each list once, for the subject lines of series children below.
        $lists = $this->lists->all()->keyBy('handle');
        $renderer = app(CampaignRenderer::class);

        $rows = $all->map(function (Campaign $campaign) use ($stats, $childCounts, $lists, $renderer) {
            // A template and a waiting child have no delivery to count yet.
            $campaignStats = in_array($campaign->status, [
                Campaign::STATUS_DRAFT, Campaign::STATUS_SERIES, Campaign::STATUS_AWAITING_APPROVAL,
            ], true) ? null : $stats->forCampaign($campaign);

            $event = (array) ($campaign->meta['event'] ?? []);

            // A series child before its send: the subject as its readers will
            // see it (its own term filled in), and the circle around the
            // venue as the audience — there is no delivery to count yet.
            $isWaitingChild = $campaign->series !== null && in_array($campaign->status, [
                Campaign::STATUS_AWAITING_APPROVAL, Campaign::STATUS_SCHEDULED,
            ], true);

            return [
                'id' => $campaign->handle,
                'handle' => $campaign->handle,
                'name' => $campaign->name,
                'subject' => $campaign->series !== null && $event !== []
                    ? $this->renderedSubject($renderer, $campaign, $lists->get((string) $campaign->listHandle))
                    : $campaign->subject,
                'audience' => $isWaitingChild ? $this->cachedSegmentMemberCount($campaign->segmentHandle) : null,
                'list' => $campaign->listHandle,
                'status' => $campaign->status,
                'status_label' => $this->statusLabel($campaign->status),
                // Series: the template's child count, a child's term.
                'series' => $campaign->series,
                'children_count' => $campaign->isSeries() ? (int) ($childCounts[$campaign->handle] ?? 0) : null,
                'event' => $event === [] ? null : [
                    'city' => (string) ($event['city'] ?? ''),
                    'date' => (string) ($event['date'] ?? ''),
                    'time' => (string) ($event['time'] ?? ''),
                ],
                'scheduled_at' => $campaign->scheduledAt?->toIso8601String(),
                'sent_at' => $campaign->sentAt?->toIso8601String(),
                'recipients' => $campaignStats['recipients'] ?? null,
                'open_rate' => $campaignStats['open_rate'] ?? null,
                'show_url' => cp_route('marketing.campaigns.show', $campaign->handle),
                'edit_url' => cp_route('marketing.campaigns.edit', $campaign->handle),
                'delete_url' => cp_route('marketing.campaigns.destroy', $campaign->handle),
                'editable' => $campaign->isEditable(),
            ];
        })->values()->all();

        $columns = collect([
            Column::make('name')->label(__('marketing::campaigns.name')),
            Column::make('subject')->label(__('marketing::campaigns.subject')),
            Column::make('list')->label(__('marketing::campaigns.list')),
            Column::make('status')->label(__('marketing::campaigns.status')),
            Column::make('scheduled_at')->label(__('marketing::campaigns.send_at')),
            Column::make('recipients')->label(__('marketing::campaigns.recipients')),
            Column::make('open_rate')->label(__('marketing::campaigns.open_rate')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('marketing::Campaigns/Index', [
            'campaigns' => $rows,
            'columns' => $columns,
            'createUrl' => cp_route('marketing.campaigns.create'),
            'canManage' => $this->userCan($request, 'manage marketing campaigns'),
            // The tabs above the listing: everything, what waits for a
            // release, and the templates that produce those.
            'tabs' => [
                ['name' => 'all', 'label' => __('marketing::campaigns.tabs.all'), 'count' => $all->count()],
                [
                    'name' => Campaign::STATUS_AWAITING_APPROVAL,
                    'label' => __('marketing::campaigns.tabs.awaiting_approval'),
                    'count' => $all->filter(fn (Campaign $c): bool => $c->status === Campaign::STATUS_AWAITING_APPROVAL)->count(),
                ],
                [
                    'name' => Campaign::STATUS_SERIES,
                    'label' => __('marketing::campaigns.tabs.series'),
                    'count' => $all->filter(fn (Campaign $c): bool => $c->isSeries())->count(),
                ],
            ],
        ]);
    }

    /** The campaign status as the reader's language says it. */
    protected function statusLabel(string $status): string
    {
        $key = 'marketing::campaigns.statuses.'.$status;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $status;
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        return Inertia::render('marketing::Campaigns/Edit', [
            'campaign' => null,
            'storeUrl' => cp_route('marketing.campaigns.store'),
            // The publish form for the campaign text: field definitions, the
            // value as Bard wants it, and the fieldtype metadata.
            'contentField' => app(CampaignContentField::class)->forEditing(null),
            'lists' => $this->listOptions(),
            'segments' => $this->segmentOptions(),
            'layouts' => $this->layoutOptions(),
            'readyMades' => $this->readyMadeOptions(),
            'mailClasses' => $this->mailClassOptions(),
            'frequencyCap' => $this->frequencyCapSummary(),
            'canSend' => $this->userCan($request, 'send marketing campaigns'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        $data = $this->validateCampaign($request);

        $handle = $data['handle'] ?? Str::snake($data['name']);

        if ($this->campaigns->find($handle)) {
            return back()->withErrors(['handle' => __('marketing::campaigns.flashes.handle_taken')]);
        }

        if ($brand = $this->handleOwnedElsewhere(HandleOwnership::CAMPAIGNS, $handle)) {
            return back()->withErrors([
                'handle' => __('marketing::campaigns.flashes.handle_taken_by_brand', ['brand' => $brand]),
            ]);
        }

        // A deleted campaign leaves its delivery rows behind — they are the
        // record of what was actually sent to whom, so they must survive. But a
        // message is identified by campaign handle plus subscriber, which means
        // a new campaign reusing the handle inherits them: the send skips every
        // recipient it already "has", finishes instantly and reports success,
        // and not one mail goes out. Refusing the handle is the only version of
        // this that neither loses history nor lies about a send.
        if (Message::query()->where('campaign_handle', $handle)->exists()) {
            return back()->withErrors(['handle' => __('marketing::campaigns.flashes.handle_has_history')]);
        }

        $campaign = new Campaign(
            handle: $handle,
            name: $data['name'],
            subject: $data['subject'] ?? '',
            variantSubject: $data['variant_subject'] ?? null,
            preheader: $data['preheader'] ?? null,
            fromName: $data['from_name'] ?? null,
            fromEmail: $data['from_email'] ?? null,
            replyTo: $data['reply_to'] ?? null,
            listHandle: $data['list'] ?? null,
            segmentHandle: $data['segment'] ?? null,
            templateHandle: $data['template'] ?? null,
            content: app(CampaignContentField::class)->fromForm($data['content'] ?? ''),
            mailClass: MailClass::fromValue($data['mail_class'] ?? null)->value,
            abShare: (int) ($data['ab_share'] ?? 0),
        );

        $this->campaigns->save($campaign);

        return redirect()
            ->to(cp_route('marketing.campaigns.edit', $handle))
            ->with('success', __('marketing::campaigns.flashes.created'));
    }

    /**
     * The campaign report: what happened, and with whom.
     *
     * One screen with five tabs, and only the open one is paid for. The
     * overview tab computes the figures and the timeline; a person tab
     * paginates its own rows and computes nothing else. The alternative —
     * shipping all five payloads on every request — would have made a report
     * of a fifty-thousand-recipient campaign five times as expensive to look
     * at as it is to read.
     *
     * The tab lives in the query string, so a reload lands where the reader
     * was, a link can be shared into a particular tab, and the pager of each
     * tab has a page number that belongs to it.
     */
    public function show(Request $request, string $handle, CampaignStats $stats, CampaignReport $report)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $tab = $report->tab($this->queryString($request, 'tab'));
        $status = $report->status($this->queryString($request, 'status'));
        $canManage = $this->userCan($request, 'manage marketing campaigns');

        $payload = $tab === CampaignReport::TAB_OVERVIEW
            ? $this->overviewPayload($campaign, $stats, $report)
            : $this->tabPayload($campaign, $report, $tab, $status, $canManage);

        $sendingStarted = in_array($campaign->status, [Campaign::STATUS_SENDING, Campaign::STATUS_SENT], true);

        return Inertia::render('marketing::Campaigns/Show', $payload + [
            'campaign' => $campaign->toArray(),
            'tab' => $tab,
            'tabs' => $this->reportTabs(),
            'statuses' => $this->statusOptions(),
            'filters' => ['status' => $status],
            'editUrl' => cp_route('marketing.campaigns.edit', $handle),
            'editable' => $campaign->isEditable(),
            // `marketing.archive.show` is only registered when the archive is
            // switched on (`routes/web.php`), and the archive ships **off**.
            // Building the URL unconditionally therefore threw
            // `RouteNotFoundException` — a 500 on the campaign screen of every
            // install that never enabled the archive, which is the default one.
            //
            // The `enabled` default here said `true` while the config file says
            // `false`, so the screen also offered a control the route behind it
            // did not have.
            'archive' => [
                'enabled' => (bool) config('marketing.archive.enabled', false),
                'released' => $campaign->inArchive,
                'live' => $campaign->isArchived(),
                'sendable_only' => ! $campaign->sentAt,
                'url' => Route::has('marketing.archive.show')
                    ? route('marketing.archive.show', ['marketingCampaign' => $campaign->handle])
                    : null,
                'update_url' => cp_route('marketing.campaigns.archive', $handle),
            ],
            'canManage' => $canManage,
            'mailPreviewUrl' => $this->mailPreviewUrl($campaign),
            // The report is about a send. Before one has started there is
            // nothing to count, and nine tiles of 0 under "Recipients" read as
            // "this goes to nobody"; the page shows the plan instead.
            'sendingStarted' => $sendingStarted,
            'audienceEstimate' => $sendingStarted || $campaign->isSeries() ? null : $this->audienceEstimate($campaign),
            'statusLabel' => $this->statusLabel($campaign->status),
            'approval' => $this->approvalPayload($request, $campaign),
        ]);
    }

    /**
     * What an editor needs to release one series child, in one place: the
     * subject as it will read, the sender, who receives it, the term and the
     * moment it goes. Null for everything that is not a series child before
     * its send.
     *
     * The recipient figure is the segment's live member count — the circle
     * around the venue — asked the same way the send asks it, so the number
     * on the button's page is the number the send starts from (before the
     * list's own consent and suppression take their share).
     *
     * @return array<string, mixed>|null
     */
    protected function approvalPayload(Request $request, Campaign $campaign): ?array
    {
        if ($campaign->series === null || ! in_array($campaign->status, [
            Campaign::STATUS_AWAITING_APPROVAL, Campaign::STATUS_SCHEDULED,
        ], true)) {
            return null;
        }

        $list = $campaign->listHandle ? $this->lists->find($campaign->listHandle) : null;
        $headline = ['subject' => $campaign->subject, 'preheader' => (string) $campaign->preheader];

        if ($list) {
            try {
                $headline = app(CampaignRenderer::class)->headline($campaign, $list);
            } catch (\Throwable) {
                // The raw lines, braces and all, are still the right answer
                // to "what is this about" — better than an empty line.
            }
        }

        $segment = $campaign->segmentHandle
            ? collect($this->segmentOptions())->firstWhere('value', $campaign->segmentHandle)
            : null;

        $template = $this->campaigns->find($campaign->series);
        $event = (array) ($campaign->meta['event'] ?? []);

        // Who it will really go out as: the brand in context (the one whose
        // campaign is open, as for a test send), through the same resolver
        // the send asks, in the same order CampaignMail applies.
        $sender = $this->resolvedSender($campaign);
        $canSend = $this->userCan($request, 'send marketing campaigns');

        return [
            'subject' => $headline['subject'],
            'preheader' => $headline['preheader'] !== '' ? $headline['preheader'] : null,
            'from_name' => $sender['name'],
            'from_email' => $sender['address'],
            'sender_refusal' => $sender['refusal'],
            'list' => $list?->name,
            'segment' => $segment['label'] ?? $campaign->segmentHandle,
            'segment_url' => $this->segmentUrl($campaign->segmentHandle),
            'recipients' => $this->segmentMemberCount($campaign->segmentHandle),
            // The circle intersected with the list's subscribers: who it
            // goes to, as opposed to who lives nearby.
            'list_recipients' => $this->audienceEstimate($campaign),
            'event' => $event === [] ? null : $event,
            'scheduled_at' => $campaign->scheduledAt?->toIso8601String(),
            'template' => $template ? [
                'name' => $template->name,
                'edit_url' => cp_route('marketing.campaigns.edit', $template->handle),
            ] : null,
            'preview_url' => $list ? cp_route('marketing.campaigns.preview', $campaign->handle) : null,
            'approve_url' => cp_route('marketing.campaigns.approve', $campaign->handle),
            'withdraw_url' => cp_route('marketing.campaigns.withdraw', $campaign->handle),
            // The editor's own test send, with this child's term in it.
            'test_url' => $canSend ? cp_route('marketing.campaigns.test', $campaign->handle) : null,
            'test_email' => $canSend ? $this->currentUserEmail($request) : null,
            'can_send' => $canSend,
        ];
    }

    /**
     * A reader for the preview, so `Hallo {{ first_name }},` reads like a
     * greeting and not like a broken template. Unsaved, with the same inert
     * token the test send uses: its unsubscribe link leads nowhere, which is
     * the point.
     */
    protected function previewReader(MailingList $list): Subscription
    {
        $subscription = new Subscription([
            'list_handle' => $list->handle,
            'email' => 'vorschau@example.com',
            'first_name' => (string) __('marketing::campaigns.preview_first_name'),
        ]);
        $subscription->token = 'test-preview';

        return $subscription;
    }

    /**
     * Who a campaign will really go out as: the brand in context (the one
     * whose campaign is open, as for a test send), through the resolver the
     * send asks, in the order CampaignMail applies.
     *
     * @return array{address: string|null, name: string|null, refusal: string|null}
     */
    protected function resolvedSender(Campaign $campaign): array
    {
        return CampaignMail::senderUnder($campaign, app(SenderIdentityResolver::class)->resolve(null));
    }

    /**
     * How many people a campaign that has not started would go to, at most:
     * the list's subscribed members, narrowed by the segment's live members
     * as the send narrows them. An upper bound — suppression and per-contact
     * opt-outs take their share only at the send — and said as one.
     *
     * Null when the list is unknown or a segment cannot be resolved.
     */
    protected function audienceEstimate(Campaign $campaign): ?int
    {
        // No lookup of the list itself: an unknown handle simply counts 0,
        // and the report page has a query budget (CampaignReportQueryCountTest).
        if (! $campaign->listHandle) {
            return null;
        }

        $query = Subscription::query()->forList($campaign->listHandle)->subscribed();

        if ($campaign->segmentHandle === null) {
            // A series child without its circle goes to nobody (fail closed).
            return $campaign->series !== null ? 0 : $query->count();
        }

        $root = LeadHub::getFacadeRoot();

        if (! $root || ! method_exists($root, 'segmentMemberIds')) {
            return null;
        }

        try {
            $ids = LeadHub::segmentMemberIds($campaign->segmentHandle);
        } catch (\Throwable) {
            return null;
        }

        return $ids === [] ? 0 : $query->whereIn('contact_uuid', $ids)->count();
    }

    /** The signed-in user's address, for "send a test to me". */
    protected function currentUserEmail(Request $request): ?string
    {
        $user = $request->user();
        $email = $user ? (method_exists($user, 'email') ? $user->email() : ($user->email ?? null)) : null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * The segment options, with a series child's own circle counted live.
     *
     * The figure the picker shows is LeadHub's materialised membership, and a
     * geo segment the sync just wrote has none yet — the editor of a child
     * read "0 contacts match" on a segment the send would reach four people
     * with. The send asks live, so for this one segment the screen does too.
     *
     * @return array<int, array{value: string, label: string, members_count: int}>
     */
    protected function segmentOptionsFor(Campaign $campaign): array
    {
        $options = $this->segmentOptions();

        if ($campaign->series === null || $campaign->segmentHandle === null) {
            return $options;
        }

        $live = $this->segmentMemberCount($campaign->segmentHandle);

        return array_map(fn (array $option): array => $option['value'] === $campaign->segmentHandle && $live !== null
            ? ['members_count' => $live] + $option
            : $option, $options);
    }

    /**
     * Where a LeadHub segment can be looked at, or null — no handle, no such
     * segment, or a LeadHub without the route. The circle a series child
     * goes to is a LeadHub segment; this is the way from the campaign to it.
     */
    protected function segmentUrl(?string $handle): ?string
    {
        if ($handle === null || ! Route::has('statamic.cp.leadhub.segments.edit')) {
            return null;
        }

        try {
            $segment = app(SegmentRepository::class)->findByHandle($handle);
        } catch (\Throwable) {
            return null;
        }

        return $segment ? cp_route('leadhub.segments.edit', $segment->getAttribute('uuid') ?? $segment->getKey()) : null;
    }

    /**
     * A child's subject with its term filled in, or the raw subject when it
     * cannot be rendered (no list, half-written Antlers) — the raw line is
     * still the right answer to "what is this about".
     */
    protected function renderedSubject(CampaignRenderer $renderer, Campaign $campaign, mixed $list): string
    {
        if (! $list instanceof MailingList) {
            return $campaign->subject;
        }

        try {
            return $renderer->headline($campaign, $list)['subject'];
        } catch (\Throwable) {
            return $campaign->subject;
        }
    }

    /**
     * {@see segmentMemberCount()} for screens that show many segments at once
     * — the listing, the template's children. Five minutes is old enough to
     * spare the geo query on every page load and young enough that the
     * figure is the one the editor expects; the approval page itself asks
     * live, because that is the number somebody acts on.
     */
    protected function cachedSegmentMemberCount(?string $handle): ?int
    {
        if ($handle === null) {
            return null;
        }

        return Cache::remember(
            'marketing.series.segment-count.'.$handle,
            now()->addMinutes(5),
            fn (): ?int => $this->segmentMemberCount($handle),
        );
    }

    /**
     * The live size of a LeadHub segment, or null when it cannot be asked —
     * no handle, or a LeadHub without `segmentMemberIds`.
     */
    protected function segmentMemberCount(?string $handle): ?int
    {
        $root = LeadHub::getFacadeRoot();

        if ($handle === null || ! $root || ! method_exists($root, 'segmentMemberIds')) {
            return null;
        }

        try {
            return count(LeadHub::segmentMemberIds($handle));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Die Mail, die rausging — als CP-Adresse fuer ein `<iframe>`.
     *
     * Kommt aus dem Versand-Schnappschuss in `statamic-email-templates`. Der
     * haelt die Vorlage mit ihren Platzhaltern; eingesetzt wird erst beim
     * Ansehen, und nichts davon wird gespeichert.
     *
     * `null` heisst dreierlei, und alle drei enden gleich: Addon nicht
     * installiert, Schnappschuesse abgeschaltet oder Migration nicht gelaufen,
     * oder diese Kampagne ist noch nicht raus. Die Seite zeigt dann kein
     * Mail-Panel statt eines Rahmens mit 404 darin.
     *
     * Eine Kampagne ist genau ein Schnappschuss: nach dem Versand ist sie nicht
     * mehr bearbeitbar ({@see Campaign::isEditable()}), es kann also keine
     * zweite Fassung geben, an der `latestForOwner()` vorbeigreifen wuerde.
     */
    protected function mailPreviewUrl(Campaign $campaign): ?string
    {
        return SendSnapshot::previewUrlFor($campaign);
    }

    /**
     * The overview tab: the figures, the split test, and the campaign as a
     * sequence of moments.
     *
     * `stats` is {@see CampaignStats} untouched — the same keys, counted the
     * same way, comparable with every campaign that came before. `humanOpens`
     * sits beside it rather than inside it, because it is a new question, not
     * a correction of an old answer.
     *
     * @return array<string, mixed>
     */
    protected function overviewPayload(Campaign $campaign, CampaignStats $stats, CampaignReport $report): array
    {
        $figures = $stats->forCampaign($campaign);

        return [
            'stats' => $figures,
            'humanOpens' => $report->humanOpens($campaign->handle, $figures),
            'timeline' => $report->timeline($campaign),
            // When it was read, rather than only how often — and with the
            // machine share kept separate, because a curve drawn from raw
            // opens says everybody read it in the first hour and that is
            // Apple's proxy, not the readers.
            'activity' => $report->activity($campaign),
            'rows' => [],
            'columns' => [],
            'pagination' => null,
            'urlBreakdown' => null,
            'exportUrl' => null,
        ];
    }

    /**
     * One person tab: fifty rows, their columns, and a way to take them away.
     *
     * @return array<string, mixed>
     */
    protected function tabPayload(
        Campaign $campaign,
        CampaignReport $report,
        string $tab,
        ?string $status,
        bool $canManage,
    ): array {
        $withErrors = $tab === CampaignReport::TAB_DELIVERY && $report->hasErrors($campaign->handle);
        $fields = $report->fields($tab, $withErrors);

        $page = $report->queryFor($tab, $campaign->handle, $status)
            ->paginate(CampaignReport::PER_PAGE)
            ->withQueryString();

        return [
            'stats' => null,
            'humanOpens' => null,
            'timeline' => null,
            'activity' => null,
            'rows' => $report->rowsFor($tab, collect($page->items())),
            'columns' => collect($fields)
                ->map(fn (string $label, string $key) => Column::make($key)->label($label)->toArray())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'urlBreakdown' => $tab === CampaignReport::TAB_CLICKS
                ? $report->urlBreakdown($campaign->handle)
                : null,
            // Offered only to somebody who could already have exported it by
            // hand. Reading a report is `view marketing`; taking a file of
            // addresses off the server is a different act.
            'exportUrl' => $canManage
                ? cp_route('marketing.campaigns.export', $campaign->handle)
                : null,
        ];
    }

    /**
     * The rows of one tab as a CSV, streamed.
     *
     * Same query, same order, same filter as the screen — it goes through
     * {@see CampaignReport::queryFor()} exactly as the page does, so the file
     * cannot describe a different selection than the one the reader was
     * looking at.
     *
     * Written out in chunks rather than collected first. A campaign can have
     * fifty thousand recipients, and an export that builds the whole table in
     * memory before sending a byte is an out-of-memory error waiting for the
     * first large campaign.
     *
     * The header carries the field keys, not their German labels: this file is
     * read by a program at least as often as by a person, and the sibling CRM's
     * export already established the convention (see LeadHub's ExportService).
     * The BOM is there for the other half of that audience — without it Excel
     * reads UTF-8 as Latin-1 and every umlaut in the file is wrong.
     */
    public function export(Request $request, string $handle, CampaignReport $report)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        // Spelled out rather than `abort_unless($campaign, 404)` like its older
        // neighbours, for the reason archive() gives: `abort()` is `never`, so
        // the analyser narrows the type here instead of being handed the
        // truthiness of an object as a bool. The nine call sites that do it the
        // other way are in the baseline; new code does not join them.
        if (! $this->campaigns->find($handle)) {
            abort(404);
        }

        $tab = $report->tab($this->queryString($request, 'tab'));

        // The overview has figures, not rows. So does an unknown tab name,
        // which `tab()` resolves to the overview.
        abort_if($tab === CampaignReport::TAB_OVERVIEW, 404);

        $status = $report->status($this->queryString($request, 'status'));
        $fields = array_keys($report->fields($tab));
        $query = $report->queryFor($tab, $handle, $status);

        $filename = $handle.'-'.$tab.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($report, $tab, $fields, $query) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            // `escape: ''` on every write. PHP 8.4 deprecates omitting it —
            // an export that logged a deprecation per row would be the loudest
            // thing in the log — and the empty string is also the correct
            // answer: RFC 4180 has no escape character, only doubled quotes,
            // and it is the default PHP is moving to.
            fputcsv($out, [...$fields, 'contact_url'], escape: '');

            $query->chunk(500, function ($models) use ($report, $tab, $fields, $out) {
                foreach ($report->rowsFor($tab, $models) as $row) {
                    fputcsv($out, [
                        ...array_map(fn (string $field) => $this->csvValue($row[$field] ?? null), $fields),
                        $this->csvValue($row['contact_url'] ?? null),
                    ], escape: '');
                }

                flush();
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A cell, as a string. `null` is an empty cell, not the word "null".
     *
     * A leading `=`, `+`, `-`, `@`, tab or carriage return is neutralised with
     * a leading apostrophe, because Excel and LibreOffice execute such a cell
     * as a formula the moment the file is opened — and the person opening it
     * is the one with `manage marketing campaigns`. The name fields come
     * straight from a public sign-up form, so a stranger picks their own
     * content for them; the BOM this export writes makes sure the spreadsheet
     * treats the file as a table rather than plain text, which is what makes
     * the route reliable rather than theoretical.
     *
     * The apostrophe is the spreadsheet's own "this is text" marker: it is
     * consumed on import and does not become part of the value.
     */
    protected function csvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) $value;

        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * A scalar query-string value, or null.
     *
     * `?tab[]=x` hands `input()` an array, and a controller that passed that
     * on to a string parameter would be a TypeError on a URL anybody can type.
     */
    protected function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<int, array{name: string, label: string}>
     */
    protected function reportTabs(): array
    {
        return array_map(fn (string $tab) => [
            'name' => $tab,
            'label' => (string) __('marketing::campaigns.report.tabs.'.$tab),
        ], CampaignReport::TABS);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function statusOptions(): array
    {
        return [
            ['value' => '', 'label' => (string) __('marketing::campaigns.report.all_statuses')],
            ...array_map(fn (string $status) => [
                'value' => $status,
                'label' => (string) __('marketing::campaigns.message_statuses.'.$status),
            ], CampaignReport::STATUSES),
        ];
    }

    /**
     * Release this campaign to the public web archive, or take it back.
     *
     * Its own endpoint rather than a field on the edit form, and that is the
     * whole reason it exists. `update()` refuses a campaign that is not
     * editable, and a campaign stops being editable the moment it is sent —
     * which is exactly when somebody decides whether the issue should be
     * readable on the web. A field on the edit form would therefore have been
     * settable only in the window before anyone could have made the decision.
     *
     * Withdrawal is the same call with `false`, and it takes effect on the next
     * request: the archive resolves visibility per request rather than caching
     * a list, so a campaign published by mistake is one toggle away from being
     * gone.
     */
    public function archive(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        $campaign = $this->campaigns->find($handle);

        // Spelled out rather than `abort_unless($campaign, 404)` like its
        // neighbours: `abort()` is `never`, so the analyser narrows the type
        // here and the truthiness of an object is not passed off as a bool.
        // The nine older call sites are in the baseline; new code does not join
        // them.
        if (! $campaign) {
            abort(404);
        }

        $data = $request->validate([
            'archive' => ['required', 'boolean'],
        ]);

        $campaign->inArchive = (bool) $data['archive'];

        $this->campaigns->save($campaign);

        return back()->with('success', $campaign->inArchive
            ? __('marketing::campaigns.flashes.archive_released')
            : __('marketing::campaigns.flashes.archive_withdrawn'));
    }

    public function edit(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        return Inertia::render('marketing::Campaigns/Edit', [
            'campaign' => $campaign->toArray(),
            'updateUrl' => cp_route('marketing.campaigns.update', $handle),
            'deleteUrl' => cp_route('marketing.campaigns.destroy', $handle),
            'sendUrl' => cp_route('marketing.campaigns.send', $handle),
            'scheduleUrl' => cp_route('marketing.campaigns.schedule', $handle),
            'unscheduleUrl' => cp_route('marketing.campaigns.unschedule', $handle),
            'testUrl' => cp_route('marketing.campaigns.test', $handle),
            'previewUrl' => cp_route('marketing.campaigns.preview', $handle),
            'livePreviewUrl' => cp_route('marketing.campaigns.live-preview'),
            'showUrl' => cp_route('marketing.campaigns.show', $handle),
            'lists' => $this->listOptions(),
            'segments' => $this->segmentOptionsFor($campaign),
            'layouts' => $this->layoutOptions(),
            'readyMades' => $this->readyMadeOptions(),
            'mailClasses' => $this->mailClassOptions(),
            'frequencyCap' => $this->frequencyCapSummary(),
            'contentField' => app(CampaignContentField::class)->forEditing($campaign->content),
            'editable' => $campaign->isEditable(),
            'canSend' => $this->userCan($request, 'send marketing campaigns'),
            // The zone a scheduled time is read in. The field is a plain
            // datetime-local, which carries no zone of its own; saying which
            // one applies is cheaper than a mail that goes out an hour early.
            'timezone' => (string) config('app.timezone', 'UTC'),
            'series' => $this->seriesPayload($campaign),
        ]);
    }

    /**
     * The "series for terms" section of the editor.
     *
     * `available` false hides the section behind a one-line hint: without
     * statamic-events there are no terms to make a series of. A child gets
     * only the way back to its template — it is part of a series, it does
     * not have one.
     *
     * @return array<string, mixed>
     */
    protected function seriesPayload(Campaign $campaign): array
    {
        $sync = app(SeriesSync::class);
        $available = SeriesSync::available();

        if ($campaign->series !== null) {
            $template = $this->campaigns->find($campaign->series);

            return [
                'available' => $available,
                'is_child' => true,
                'template' => $template ? [
                    'name' => $template->name,
                    'edit_url' => cp_route('marketing.campaigns.edit', $template->handle),
                ] : null,
                // Read-only on the child's editor: the sender it will really
                // go out as, the same answer as on its approval page.
                'sender' => $this->resolvedSender($campaign),
            ];
        }

        $children = $campaign->isSeries() ? $sync->childrenOf($campaign) : collect();

        return [
            'available' => $available,
            'is_child' => false,
            'enabled' => $campaign->isSeries(),
            // A draft may become a template; a template may go back while it
            // has produced nothing. Scheduled or sent campaigns are neither.
            'can_toggle' => $available && ($campaign->isDraft() || ($campaign->isSeries() && $children->isEmpty())),
            'settings' => $sync->settingsFor($campaign),
            'events' => $available ? $sync->eventOptions() : [],
            'skipped_no_postal_code' => $sync->missingPostalCodes($campaign),
            'skipped_no_presale' => $sync->missingPresale($campaign),
            // statamic-events 2.7+ carries the presale date; before that a
            // presale series has nothing to hang on, and the editor says so.
            'presale_supported' => SeriesSync::presaleSupported(),
            'columns' => collect([
                Column::make('city')->label(__('marketing::series.city')),
                Column::make('term')->label(__('marketing::series.term')),
                Column::make('scheduled_at')->label(__('marketing::campaigns.send_at')),
                Column::make('status')->label(__('marketing::campaigns.status')),
                Column::make('recipients')->label(__('marketing::campaigns.recipients')),
            ])->map(fn (Column $column): array => $column->toArray())->all(),
            'children' => $children->map(fn (Campaign $child): array => [
                'id' => $child->handle,
                'handle' => $child->handle,
                'name' => $child->name,
                'city' => (string) ($child->meta['event']['city'] ?? ''),
                'date' => (string) ($child->meta['event']['date'] ?? ''),
                'time' => (string) ($child->meta['event']['time'] ?? ''),
                'term' => (string) ($child->meta['event']['starts_at'] ?? ''),
                'venue' => (string) ($child->meta['event']['venue'] ?? ''),
                'scheduled_at' => $child->scheduledAt?->toIso8601String(),
                'status' => $child->status,
                'status_label' => $this->statusLabel($child->status),
                'recipients' => $this->cachedSegmentMemberCount($child->segmentHandle),
                'segment_url' => $this->segmentUrl($child->segmentHandle),
                'show_url' => cp_route('marketing.campaigns.show', $child->handle),
            ])->values()->all(),
        ];
    }

    public function update(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        if (! $campaign->isEditable()) {
            return back()->withErrors(['status' => __('marketing::campaigns.flashes.not_editable')]);
        }

        $data = $this->validateCampaign($request);
        $series = $this->validateSeries($request, $campaign);

        $campaign->name = $data['name'];
        $campaign->subject = $data['subject'] ?? '';
        $campaign->variantSubject = $data['variant_subject'] ?? null;
        $campaign->preheader = $data['preheader'] ?? null;
        $campaign->fromName = $data['from_name'] ?? null;
        $campaign->fromEmail = $data['from_email'] ?? null;
        $campaign->replyTo = $data['reply_to'] ?? null;
        // A series child is built around its list and its circle segment; the
        // form may not move either (an empty segment would mean the whole
        // list). Templates and ordinary campaigns choose freely.
        if ($campaign->series === null) {
            $campaign->listHandle = $data['list'] ?? null;
            $campaign->segmentHandle = $data['segment'] ?? null;
        }

        $campaign->templateHandle = $data['template'] ?? null;
        $campaign->content = app(CampaignContentField::class)->fromForm($data['content'] ?? '');
        $campaign->mailClass = MailClass::fromValue($data['mail_class'] ?? null)->value;
        $campaign->abShare = (int) ($data['ab_share'] ?? 0);

        if ($series !== null) {
            $this->applySeries($campaign, $series);
        }

        $this->campaigns->save($campaign);

        // Saving a series template is the editor's way of changing what the
        // series does; the children answer immediately rather than on the
        // night run.
        if ($campaign->isSeries()) {
            $result = app(SeriesSync::class)->syncTemplate($campaign);

            return back()->with('success', __('marketing::campaigns.flashes.updated').' '.__('marketing::series.summary', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'removed' => $result['removed'],
                'skipped' => $result['skipped_no_postal_code'],
                'presale' => $result['skipped_no_presale'],
            ]));
        }

        return back()->with('success', __('marketing::campaigns.flashes.updated'));
    }

    /**
     * The series half of an update, validated — or null when the form did
     * not send it (an API client, an older screen, a child). Absent means
     * "leave the series alone", never "switch it off".
     *
     * @return array{enabled: bool, settings: array<string, mixed>}|null
     */
    protected function validateSeries(Request $request, Campaign $campaign): ?array
    {
        if (! $request->has('series_enabled') || $campaign->series !== null) {
            return null;
        }

        $data = $request->validate([
            'series_enabled' => ['required', 'boolean'],
            'series' => ['nullable', 'array'],
            'series.radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'series.days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'series.send_time' => ['nullable', 'date_format:H:i'],
            'series.event_ids' => ['nullable', 'array'],
            'series.event_ids.*' => ['string'],
            'series.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'series.anchor' => ['nullable', Rule::in([SeriesSync::ANCHOR_CONCERT, SeriesSync::ANCHOR_PRESALE])],
            'series.days_after_presale' => ['nullable', 'integer', 'min:0', 'max:365'],
            'series.more_enabled' => ['nullable', 'boolean'],
            'series.more_radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'series.more_limit' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $enabled = (bool) $data['series_enabled'];

        if ($enabled && ! $campaign->isSeries()) {
            if (! SeriesSync::available()) {
                throw ValidationException::withMessages(['series_enabled' => __('marketing::series.not_installed')]);
            }

            if (! $campaign->isDraft()) {
                throw ValidationException::withMessages(['series_enabled' => __('marketing::series.errors.only_drafts')]);
            }
        }

        if (! $enabled && $campaign->isSeries() && app(SeriesSync::class)->childrenOf($campaign)->isNotEmpty()) {
            throw ValidationException::withMessages(['series_enabled' => __('marketing::series.errors.has_children')]);
        }

        return ['enabled' => $enabled, 'settings' => (array) ($data['series'] ?? [])];
    }

    /**
     * Turn a draft into a template, a childless template back into a draft,
     * and store the settings. `preview_event` survives a save: the form does
     * not edit it, and dropping it would change the preview behind the
     * editor's back.
     *
     * @param  array{enabled: bool, settings: array<string, mixed>}  $series
     */
    protected function applySeries(Campaign $campaign, array $series): void
    {
        $meta = $campaign->meta;

        if (! $series['enabled']) {
            if ($campaign->isSeries()) {
                $campaign->status = Campaign::STATUS_DRAFT;
                unset($meta['series']);
                $campaign->meta = $meta;
            }

            return;
        }

        $settings = $series['settings'];
        $stored = (array) ($meta['series'] ?? []);

        $meta['series'] = array_filter([
            'radius_km' => isset($settings['radius_km']) ? (int) $settings['radius_km'] : SeriesSync::DEFAULT_SETTINGS['radius_km'],
            'days_before' => isset($settings['days_before']) ? (int) $settings['days_before'] : SeriesSync::DEFAULT_SETTINGS['days_before'],
            'send_time' => (string) ($settings['send_time'] ?? SeriesSync::DEFAULT_SETTINGS['send_time']),
            'event_ids' => array_values(array_map('strval', (array) ($settings['event_ids'] ?? []))),
            'country' => strtoupper((string) ($settings['country'] ?? SeriesSync::DEFAULT_SETTINGS['country'])),
            'anchor' => (string) ($settings['anchor'] ?? $stored['anchor'] ?? SeriesSync::DEFAULT_SETTINGS['anchor']),
            'days_after_presale' => isset($settings['days_after_presale'])
                ? (int) $settings['days_after_presale']
                : (int) ($stored['days_after_presale'] ?? SeriesSync::DEFAULT_SETTINGS['days_after_presale']),
            'more_enabled' => array_key_exists('more_enabled', $settings) && $settings['more_enabled'] !== null
                ? (bool) $settings['more_enabled']
                : (bool) ($stored['more_enabled'] ?? SeriesSync::DEFAULT_SETTINGS['more_enabled']),
            'more_radius_km' => isset($settings['more_radius_km'])
                ? (int) $settings['more_radius_km']
                : (int) ($stored['more_radius_km'] ?? SeriesSync::DEFAULT_SETTINGS['more_radius_km']),
            'more_limit' => isset($settings['more_limit'])
                ? (int) $settings['more_limit']
                : (int) ($stored['more_limit'] ?? SeriesSync::DEFAULT_SETTINGS['more_limit']),
            'preview_event' => $stored['preview_event'] ?? null,
        ], fn ($value): bool => $value !== null);

        $campaign->meta = $meta;
        $campaign->status = Campaign::STATUS_SERIES;
    }

    public function destroy(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $this->campaigns->delete($handle);

        // A template's unsent children and their unused segments go with it
        // now, not at the night run.
        if ($campaign->isSeries()) {
            app(SeriesSync::class)->cleanUp();
        }

        return redirect()
            ->to(cp_route('marketing.campaigns.index'))
            ->with('success', __('marketing::campaigns.flashes.deleted'));
    }

    public function send(Request $request, string $handle, CampaignSender $sender)
    {
        $this->authorizeOrFail($request, 'send marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        try {
            $sender->queue($campaign);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return redirect()
            ->to(cp_route('marketing.campaigns.show', $handle))
            ->with('success', __('marketing::campaigns.flashes.sending'));
    }

    public function schedule(Request $request, string $handle, CampaignSender $sender)
    {
        $this->authorizeOrFail($request, 'send marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        try {
            $sender->schedule($campaign, CarbonImmutable::parse($data['scheduled_at']));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return back()->with('success', __('marketing::campaigns.flashes.scheduled'));
    }

    public function unschedule(Request $request, string $handle, CampaignSender $sender)
    {
        $this->authorizeOrFail($request, 'send marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $sender->unschedule($campaign);

        return back()->with('success', __('marketing::campaigns.flashes.unscheduled'));
    }

    /**
     * Release a waiting series child into its send time. Endpoint only for
     * now — the Vue side of the series is its own build phase; the route is
     * the whole contract that phase may rely on.
     */
    public function approve(Request $request, string $handle, CampaignSender $sender)
    {
        $this->authorizeOrFail($request, 'send marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_if($campaign === null, 404);

        try {
            $sender->approve($campaign);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return back()->with('success', __('marketing::campaigns.flashes.approved'));
    }

    /** Take a release back: a scheduled series child returns to waiting. */
    public function withdraw(Request $request, string $handle, CampaignSender $sender)
    {
        $this->authorizeOrFail($request, 'send marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_if($campaign === null, 404);

        $sender->withdraw($campaign);

        return back()->with('success', __('marketing::campaigns.flashes.withdrawn'));
    }

    public function sendTest(Request $request, string $handle, CampaignSender $sender)
    {
        $this->authorizeOrFail($request, 'send marketing campaigns');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $sender->sendTest($campaign, $data['email']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return back()->with('success', __('marketing::campaigns.flashes.test_sent'));
    }

    /**
     * Rendered HTML preview with sample subscriber data, shown in an iframe.
     *
     * The body of this response is HTML a Control Panel user wrote — the
     * campaign content and the e-mail template around it — served from a
     * Control Panel route, which is to say from the session's own origin. A
     * `<script>` in a template would otherwise run as whoever previews a
     * campaign using it, so an editor with `manage marketing templates`
     * becomes every super user who looks. The barrier is two-sided and both
     * sides are needed:
     *
     *  - here, `Content-Security-Policy: sandbox` puts the document in a
     *    unique opaque origin with scripts and forms off, and it holds even
     *    when the HTML is opened straight into a tab, which the "open in new
     *    tab" link invites;
     *  - in `Campaigns/Edit.vue`, the iframe carries `sandbox` with neither
     *    `allow-scripts` nor `allow-same-origin`, which holds when the header
     *    does not reach the parser.
     *
     * `default-src 'none'` is the floor, then exactly what an e-mail needs is
     * handed back: images (a campaign without its images is not a preview) and
     * inline styles (e-mail HTML has no other kind). Scripts are never handed
     * back — that is the whole point — and `nosniff` stops the response being
     * re-read as anything but the HTML it says it is.
     *
     * Guarded by `tests/Feature/CampaignPreviewIsolationTest.php` and
     * `tests/js/preview-sandbox.test.js`.
     */
    /**
     * Die Vorschau dessen, was gerade getippt wird — nicht des zuletzt
     * Gespeicherten.
     *
     * Bis hierher zeigte die Kampagnen-Vorschau die gespeicherte Fassung, und
     * die Oberflaeche sagte es auch dazu („Save your changes first"). Damit war
     * sie drei Schritte vom Bearbeiteten entfernt: schreiben, speichern,
     * ansehen. Die Vorlagen-Seite dieses Addons kann es laengst besser; das hier
     * ist dasselbe Muster fuer Kampagnen.
     *
     * Gerendert wird durch **denselben** `CampaignRenderer`, den der echte
     * Versand nimmt. Ein zweiter Renderer waere ein zweites Ding, das man in
     * Gleichschritt halten muss, und die erste Abweichung faende jemand in
     * seinem Posteingang.
     *
     * Nichts wird gespeichert. Die Kampagne wird aus den geschickten Werten
     * gebaut und nach dem Rendern weggeworfen.
     */
    public function livePreview(Request $request, CampaignRenderer $renderer)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $listHandle = (string) $request->input('list_handle', '');
        $list = $listHandle !== '' ? $this->lists->find($listHandle) : null;

        if (! $list) {
            return response()->json(['data' => [
                'html' => '',
                'error' => __('marketing::campaigns.errors.no_list'),
            ]]);
        }

        $campaign = new Campaign(
            handle: (string) $request->input('handle', 'vorschau'),
            name: (string) $request->input('name', ''),
            subject: (string) $request->input('subject', ''),
            // Durch dieselbe Umwandlung wie beim Speichern. Das Feld schickt
            // Bard-Werte, keinen HTML-String; ein `(string)` darauf ist eine
            // „Array to string conversion" und eine leere Vorschau.
            content: app(CampaignContentField::class)->fromForm($request->input('content')),
            listHandle: $list->handle,
            templateHandle: $request->input('template_handle') ?: null,
            preheader: $request->input('preheader') ?: null,
        );

        // `{{ event:… }}` needs a term to render against. A series child
        // brings its own snapshot, a template (saved, or being switched on
        // right now) the sample term — read from the saved row, so the
        // preview of a child shows its city and not the sample's.
        $saved = $this->campaigns->find($campaign->handle);

        if ($saved && $saved->series !== null) {
            $campaign->series = $saved->series;
            $campaign->meta = $saved->meta;
        } elseif ($request->boolean('series') || $saved?->isSeries()) {
            $campaign->status = Campaign::STATUS_SERIES;
            $campaign->meta = $saved ? $saved->meta : [];

            // A template that has produced children previews against a real
            // term — the one picked, the first one by default — rather than
            // the invented sample. Only its own children: the handle comes
            // from the browser.
            $previewChild = (string) $request->input('preview_child', '');
            $child = $previewChild !== '' ? $this->campaigns->find($previewChild) : null;

            if ($child && $child->series === $campaign->handle && ! empty($child->meta['event'])) {
                $campaign->meta = [
                    'event' => $child->meta['event'],
                    'more_events' => $child->meta['more_events'] ?? [],
                ] + $campaign->meta;
            }
        }

        try {
            $rendered = $renderer->render($campaign, $list, $this->previewReader($list));
        } catch (\Throwable $e) {
            // Halb getippte Antlers ist der Normalzustand einer Kampagne, an
            // der jemand schreibt. Die Meldung geht zurueck, die Seite behaelt
            // ihr letztes Bild — eine Vorschau, die bei jeder offenen Klammer
            // weiss wird, ist schlimmer als keine.
            return response()->json(['data' => [
                'html' => '',
                'error' => $e->getMessage(),
            ]]);
        }

        return response()->json(['data' => [
            'html' => $rendered->html,
            'error' => null,
        ]]);
    }

    public function preview(Request $request, string $handle, CampaignRenderer $renderer)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $list = $campaign->listHandle ? $this->lists->find($campaign->listHandle) : null;

        abort_unless($list, 422, __('marketing::campaigns.errors.no_list'));

        // A template previews against its first real term, as in the editor.
        if ($campaign->isSeries()) {
            $first = app(SeriesSync::class)->childrenOf($campaign)->first();

            if ($first && ! empty($first->meta['event'])) {
                $campaign->meta = [
                    'event' => $first->meta['event'],
                    'more_events' => $first->meta['more_events'] ?? [],
                ] + $campaign->meta;
            }
        }

        $rendered = $renderer->render($campaign, $list, $this->previewReader($list));

        return response($rendered->html)->withHeaders([
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline'; font-src data: https:",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    protected function validateCampaign(Request $request): array
    {
        $data = $request->validate([
            // 0 or 10–50. Anything in between is a test on too few people to
            // say anything, and above half there is no "rest" left to send a
            // winner to. Validated now, acted on in Phase 2 — see Campaign.
            'ab_share' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                $share = (int) $value;

                if ($share !== 0 && ($share < 10 || $share > 50)) {
                    $fail(__('marketing::campaigns.errors.ab_share_range'));
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'subject' => ['nullable', 'string', 'max:255'],
            // Present means "this campaign is an A/B test on the subject line".
            // Absent or blank means it is not — see Campaign::hasVariants().
            'variant_subject' => ['nullable', 'string', 'max:255'],
            // The frequency-cap classification. Validated against the enum, so
            // a value the send path cannot act on never reaches storage —
            // MailClass::fromValue() would silently read it back as
            // `marketing`, and an editor who picked "reminder" and got a capped
            // campaign would have no way to see why.
            'mail_class' => ['nullable', Rule::in(MailClass::values())],
            'preheader' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_email' => ['nullable', 'email'],
            'reply_to' => ['nullable', 'email'],
            'list' => ['nullable', 'string'],
            'segment' => ['nullable', 'string'],
            'template' => ['nullable', 'string'],
            // Either a string (API, import, anything that posts plain HTML) or
            // the Bard document the publish form submits. What gets stored is
            // always a string — see CampaignContentField::fromForm().
            'content' => ['nullable'],
            'content.*' => ['nullable'],
        ]);

        // A share without a second subject is a test with one arm.
        if ((int) ($data['ab_share'] ?? 0) > 0 && trim((string) ($data['variant_subject'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'ab_share' => __('marketing::campaigns.errors.ab_share_needs_variant'),
            ]);
        }

        return $data;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function mailClassOptions(): array
    {
        return array_map(fn (MailClass $class) => [
            'value' => $class->value,
            'label' => $class->label(),
        ], MailClass::cases());
    }

    /**
     * What the cap is set to, so the edit screen can say what choosing a class
     * actually means here instead of describing a feature in the abstract.
     *
     * Only the two numbers and the on/off state. No config value that is not
     * already visible on the screen it describes goes to the browser.
     *
     * @return array{enabled: bool, max: int, window_hours: int}
     */
    protected function frequencyCapSummary(): array
    {
        $cap = app(FrequencyCap::class);

        return [
            'enabled' => $cap->enabled(),
            'max' => $cap->limit(),
            'window_hours' => $cap->windowHours(),
        ];
    }

    protected function listOptions(): array
    {
        return $this->lists->all()
            ->map(fn ($list) => ['value' => $list->handle, 'label' => $list->name])
            ->values()
            ->all();
    }

    /**
     * The layouts a campaign can be sent in — the envelopes, and nothing else.
     *
     * Until 2.7.0 this list also carried the managed email-template entries, so
     * one select offered two different kinds of thing under one word. Choosing
     * a finished mail there made it the campaign's *layout*, and because a
     * finished mail has no `{{ content }}` hole, the campaign's own text was
     * dropped without a word. Adrian found it by opening the select and asking
     * what the FamilyStack mails were doing in it.
     *
     * `has_content_hole` travels with each option so the editor can say, before
     * anything is sent, that this layout would swallow the text.
     *
     * @return array<int, array{value: string, label: string, has_content_hole: bool}>
     */
    protected function layoutOptions(): array
    {
        return $this->templates->all()
            ->map(fn ($template) => [
                'value' => $template->handle,
                'label' => $template->name,
                'has_content_hole' => $this->hasContentHole($template->html),
            ])
            ->values()
            ->all();
    }

    /**
     * The finished mails a campaign can send instead of writing its own.
     *
     * A separate list, and a separate control on screen. Both still write into
     * the same stored `template` handle — the send path is unchanged and old
     * campaigns keep resolving exactly as they did.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function readyMadeOptions(): array
    {
        $layouts = collect($this->layoutOptions())->pluck('value')->all();

        // A slug that is already a layout stays a layout: at render time the
        // managed entry wins, so offering it in both lists would be offering
        // the same choice twice with two different meanings.
        return collect($this->emailTemplateEntryOptions())
            ->reject(fn ($option) => in_array($option['value'], $layouts, true))
            ->values()
            ->all();
    }

    /** Does this layout leave a hole for the campaign text? */
    protected function hasContentHole(string $html): bool
    {
        return (bool) preg_match('/\{\{\s*content\s*\}\}/', $html);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function emailTemplateEntryOptions(): array
    {
        // One lookup for both screens that pick a managed template — this one
        // and the sequence editor. See Support\EmailTemplateOptions.
        return array_map(
            fn (array $option) => ['value' => $option['value'], 'label' => $option['label']],
            EmailTemplateOptions::all(),
        );
    }

    /**
     * Segment options for the campaign audience picker, from LeadHub.
     *
     * Guarded: if the installed LeadHub predates segments (no `segments()` on
     * the facade root), returns an empty array so the picker hides itself and
     * campaigns keep sending to the whole list. Facades proxy via __callStatic,
     * so method_exists targets the resolved root object.
     *
     * @return array<int,array{value:string,label:string,members_count:int}>
     */
    protected function segmentOptions(): array
    {
        $root = LeadHub::getFacadeRoot();

        if (! $root || ! method_exists($root, 'segments')) {
            return [];
        }

        return collect(LeadHub::segments())
            ->filter(fn ($segment) => $segment['is_active'] ?? true)
            ->map(fn ($segment) => [
                'value' => (string) $segment['handle'],
                'label' => (string) $segment['name'],
                'members_count' => (int) ($segment['members_count'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
