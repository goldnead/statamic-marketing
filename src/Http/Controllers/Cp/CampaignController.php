<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Carbon\CarbonImmutable;
use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Support\CampaignContentField;
use Goldnead\Marketing\Support\HandleOwnership;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $rows = $this->campaigns->all()->map(function (Campaign $campaign) use ($stats) {
            $campaignStats = $campaign->isDraft() ? null : $stats->forCampaign($campaign);

            return [
                'id' => $campaign->handle,
                'handle' => $campaign->handle,
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'list' => $campaign->listHandle,
                'status' => $campaign->status,
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
            Column::make('recipients')->label(__('marketing::campaigns.recipients')),
            Column::make('open_rate')->label(__('marketing::campaigns.open_rate')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('marketing::Campaigns/Index', [
            'campaigns' => $rows,
            'columns' => $columns,
            'createUrl' => cp_route('marketing.campaigns.create'),
            'canManage' => $this->userCan($request, 'manage marketing campaigns'),
        ]);
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
        );

        $this->campaigns->save($campaign);

        return redirect()
            ->to(cp_route('marketing.campaigns.edit', $handle))
            ->with('success', __('marketing::campaigns.flashes.created'));
    }

    public function show(Request $request, string $handle, CampaignStats $stats)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $messages = Message::forCampaign($handle)
            ->with('subscription')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $rows = collect($messages->items())->map(fn (Message $message) => [
            'id' => $message->uuid,
            'email' => $message->email,
            'status' => $message->status,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'opens' => $message->opens,
            'clicks' => $message->clicks,
        ])->all();

        $columns = collect([
            Column::make('email')->label(__('marketing::subscribers.email')),
            Column::make('status')->label(__('marketing::campaigns.status')),
            Column::make('sent_at')->label(__('marketing::campaigns.sent_at')),
            Column::make('opens')->label(__('marketing::campaigns.opens')),
            Column::make('clicks')->label(__('marketing::campaigns.clicks')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('marketing::Campaigns/Show', [
            'campaign' => $campaign->toArray(),
            'stats' => $stats->forCampaign($campaign),
            'messages' => $rows,
            'columns' => $columns,
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ],
            'editUrl' => cp_route('marketing.campaigns.edit', $handle),
            'editable' => $campaign->isEditable(),
            'archive' => [
                'enabled' => (bool) config('marketing.archive.enabled', true),
                'released' => $campaign->inArchive,
                'live' => $campaign->isArchived(),
                'sendable_only' => ! $campaign->sentAt,
                'url' => route('marketing.archive.show', ['marketingCampaign' => $campaign->handle]),
                'update_url' => cp_route('marketing.campaigns.archive', $handle),
            ],
            'canManage' => $this->userCan($request, 'manage marketing campaigns'),
        ]);
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
            'showUrl' => cp_route('marketing.campaigns.show', $handle),
            'lists' => $this->listOptions(),
            'segments' => $this->segmentOptions(),
            'layouts' => $this->layoutOptions(),
            'readyMades' => $this->readyMadeOptions(),
            'mailClasses' => $this->mailClassOptions(),
            'frequencyCap' => $this->frequencyCapSummary(),
            'contentField' => app(CampaignContentField::class)->forEditing($campaign->content),
            'editable' => $campaign->isEditable(),
            'canSend' => $this->userCan($request, 'send marketing campaigns'),
        ]);
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

        $campaign->name = $data['name'];
        $campaign->subject = $data['subject'] ?? '';
        $campaign->variantSubject = $data['variant_subject'] ?? null;
        $campaign->preheader = $data['preheader'] ?? null;
        $campaign->fromName = $data['from_name'] ?? null;
        $campaign->fromEmail = $data['from_email'] ?? null;
        $campaign->replyTo = $data['reply_to'] ?? null;
        $campaign->listHandle = $data['list'] ?? null;
        $campaign->segmentHandle = $data['segment'] ?? null;
        $campaign->templateHandle = $data['template'] ?? null;
        $campaign->content = app(CampaignContentField::class)->fromForm($data['content'] ?? '');
        $campaign->mailClass = MailClass::fromValue($data['mail_class'] ?? null)->value;

        $this->campaigns->save($campaign);

        return back()->with('success', __('marketing::campaigns.flashes.updated'));
    }

    public function destroy(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing campaigns');

        abort_unless($this->campaigns->find($handle), 404);

        $this->campaigns->delete($handle);

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
    public function preview(Request $request, string $handle, CampaignRenderer $renderer)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $list = $campaign->listHandle ? $this->lists->find($campaign->listHandle) : null;

        abort_unless($list, 422, __('marketing::campaigns.errors.no_list'));

        $rendered = $renderer->render($campaign, $list);

        return response($rendered->html)->withHeaders([
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline'; font-src data: https:",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    protected function validateCampaign(Request $request): array
    {
        return $request->validate([
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
        if (! class_exists(EmailTemplates::class)
            || ! class_exists(Entry::class)) {
            return [];
        }

        try {
            // Handle comes from the addon itself (single source of truth); the
            // addon owns `et_templates` to avoid colliding with any unrelated
            // host-app `email_templates` collection.
            $handle = EmailTemplateCollectionManager::HANDLE;

            return collect(Entry::query()->where('collection', $handle)->get())
                ->map(fn ($entry) => [
                    'value' => (string) $entry->slug(),
                    'label' => (string) ($entry->value('title') ?? $entry->slug()),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
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
