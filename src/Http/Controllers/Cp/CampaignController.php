<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\CampaignStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use Statamic\CP\Column;
use Statamic\Support\Str;

class CampaignController extends Controller
{
    public function __construct(
        protected CampaignRepository $campaigns,
        protected MailingListRepository $lists,
        protected EmailTemplateRepository $templates,
    ) {
    }

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
            'lists' => $this->listOptions(),
            'segments' => $this->segmentOptions(),
            'templates' => $this->templateOptions(),
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

        $campaign = new Campaign(
            handle: $handle,
            name: $data['name'],
            subject: $data['subject'] ?? '',
            preheader: $data['preheader'] ?? null,
            fromName: $data['from_name'] ?? null,
            fromEmail: $data['from_email'] ?? null,
            replyTo: $data['reply_to'] ?? null,
            listHandle: $data['list'] ?? null,
            segmentHandle: $data['segment'] ?? null,
            templateHandle: $data['template'] ?? null,
            content: $data['content'] ?? '',
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
        ]);
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
            'templates' => $this->templateOptions(),
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
        $campaign->preheader = $data['preheader'] ?? null;
        $campaign->fromName = $data['from_name'] ?? null;
        $campaign->fromEmail = $data['from_email'] ?? null;
        $campaign->replyTo = $data['reply_to'] ?? null;
        $campaign->listHandle = $data['list'] ?? null;
        $campaign->segmentHandle = $data['segment'] ?? null;
        $campaign->templateHandle = $data['template'] ?? null;
        $campaign->content = $data['content'] ?? '';

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

    /** Rendered HTML preview with sample subscriber data, shown in an iframe. */
    public function preview(Request $request, string $handle, CampaignRenderer $renderer)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $campaign = $this->campaigns->find($handle);
        abort_unless($campaign, 404);

        $list = $campaign->listHandle ? $this->lists->find($campaign->listHandle) : null;

        abort_unless($list, 422, 'Campaign has no valid mailing list.');

        $rendered = $renderer->render($campaign, $list);

        return response($rendered->html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected function validateCampaign(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_email' => ['nullable', 'email'],
            'reply_to' => ['nullable', 'email'],
            'list' => ['nullable', 'string'],
            'segment' => ['nullable', 'string'],
            'template' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
        ]);
    }

    protected function listOptions(): array
    {
        return $this->lists->all()
            ->map(fn ($list) => ['value' => $list->handle, 'label' => $list->name])
            ->values()
            ->all();
    }

    protected function templateOptions(): array
    {
        $options = $this->templates->all()
            ->map(fn ($template) => ['value' => $template->handle, 'label' => $template->name])
            ->values()
            ->all();

        $seen = collect($options)->pluck('value')->all();

        // When the optional email-templates addon is installed, offer its
        // managed template entries too (referenced by slug). A slug
        // already served by a marketing template is skipped so the select never
        // shows a duplicate option; at render time the managed entry wins.
        foreach ($this->emailTemplateEntryOptions() as $option) {
            if (! in_array($option['value'], $seen, true)) {
                $options[] = $option;
                $seen[] = $option['value'];
            }
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function emailTemplateEntryOptions(): array
    {
        if (! class_exists(\Goldnead\EmailTemplates\Facades\EmailTemplates::class)
            || ! class_exists(\Statamic\Facades\Entry::class)) {
            return [];
        }

        try {
            // Handle comes from the addon itself (single source of truth); the
            // addon owns `et_templates` to avoid colliding with any unrelated
            // host-app `email_templates` collection.
            $handle = \Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager::HANDLE;

            return collect(\Statamic\Facades\Entry::query()->where('collection', $handle)->get())
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
        $root = \Goldnead\Leadhub\Facades\LeadHub::getFacadeRoot();

        if (! $root || ! method_exists($root, 'segments')) {
            return [];
        }

        return collect(\Goldnead\Leadhub\Facades\LeadHub::segments())
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
