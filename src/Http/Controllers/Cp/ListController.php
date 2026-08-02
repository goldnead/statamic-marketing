<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\MailLogEntry;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Support\HandleOwnership;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Support\Str;

class ListController extends Controller
{
    public function __construct(protected MailingListRepository $lists) {}

    public function index(Request $request, CampaignStats $stats)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $rows = $this->lists->all()->map(function (MailingList $list) use ($stats) {
            $listStats = $stats->forList($list->handle);

            return [
                'id' => $list->handle,
                'handle' => $list->handle,
                'name' => $list->name,
                'double_opt_in' => $list->usesDoubleOptIn(),
                'subscribed' => $listStats['subscribed'],
                'pending' => $listStats['pending'],
                'show_url' => cp_route('marketing.lists.show', $list->handle),
                'edit_url' => cp_route('marketing.lists.edit', $list->handle),
                'delete_url' => cp_route('marketing.lists.destroy', $list->handle),
            ];
        })->values()->all();

        $columns = collect([
            Column::make('name')->label(__('marketing::lists.name')),
            Column::make('handle')->label(__('marketing::lists.handle')),
            Column::make('double_opt_in')->label(__('marketing::lists.double_opt_in')),
            Column::make('subscribed')->label(__('marketing::lists.subscribed')),
            Column::make('pending')->label(__('marketing::lists.pending')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('marketing::Lists/Index', [
            'lists' => $rows,
            'columns' => $columns,
            'createUrl' => cp_route('marketing.lists.create'),
            'canManage' => $this->userCan($request, 'manage marketing lists'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing lists');

        return Inertia::render('marketing::Lists/Edit', [
            'list' => null,
            'storeUrl' => cp_route('marketing.lists.store'),
            'defaultDoubleOptIn' => (bool) config('marketing.subscriptions.double_opt_in', true),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing lists');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'description' => ['nullable', 'string'],
            'double_opt_in' => ['nullable', 'boolean'],
        ]);

        $handle = $data['handle'] ?: Str::snake($data['name']);

        if ($this->lists->find($handle)) {
            return back()->withErrors(['handle' => __('marketing::lists.flashes.handle_taken')]);
        }

        if ($brand = $this->handleOwnedElsewhere(HandleOwnership::LISTS, $handle)) {
            return back()->withErrors([
                'handle' => __('marketing::lists.flashes.handle_taken_by_brand', ['brand' => $brand]),
            ]);
        }

        $this->lists->save(new MailingList(
            handle: $handle,
            name: $data['name'],
            description: $data['description'] ?? null,
            doubleOptIn: $data['double_opt_in'] ?? null,
        ));

        return redirect()
            ->to(cp_route('marketing.lists.show', $handle))
            ->with('success', __('marketing::lists.flashes.created'));
    }

    public function show(Request $request, string $handle, CampaignStats $stats)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $list = $this->lists->find($handle);
        abort_unless($list, 404);

        $status = (string) $request->input('status', '');

        $page = Subscription::forList($handle)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('subscribed_at')
            ->paginate(50)
            ->withQueryString();

        $frequency = $this->frequencyFor(collect($page->items()));

        $rows = collect($page->items())->map(fn (Subscription $subscription) => [
            'id' => $subscription->uuid,
            'email' => $subscription->email,
            'name' => trim(($subscription->first_name ?? '').' '.($subscription->last_name ?? '')),
            'status' => $subscription->status,
            'subscribed_at' => $subscription->subscribed_at?->toIso8601String(),
            'contact_uuid' => $subscription->contact_uuid,
            'frequency' => $frequency[$subscription->id] ?? null,
            'unsubscribe_url' => cp_route('marketing.lists.subscribers.unsubscribe', [$handle, $subscription->uuid]),
            'delete_url' => cp_route('marketing.lists.subscribers.destroy', [$handle, $subscription->uuid]),
        ])->all();

        $cap = app(FrequencyCap::class);

        $columns = collect([
            Column::make('email')->label(__('marketing::subscribers.email')),
            Column::make('name')->label(__('marketing::subscribers.name')),
            Column::make('status')->label(__('marketing::subscribers.status')),
            Column::make('subscribed_at')->label(__('marketing::subscribers.subscribed_at')),
        ])
            // Only where a cap is configured. A column of "0/3" on an install
            // that caps nothing is noise pretending to be information.
            ->when($cap->enabled(), fn ($columns) => $columns->push(
                Column::make('frequency')->label(__('marketing::subscribers.frequency'))
            ))
            ->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('marketing::Lists/Show', [
            'list' => array_merge($list->toArray(), ['double_opt_in_effective' => $list->usesDoubleOptIn()]),
            'stats' => $stats->forList($handle),
            'subscribers' => $rows,
            'columns' => $columns,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'filters' => ['status' => $status, 'search' => (string) $request->input('search', '')],
            'editUrl' => cp_route('marketing.lists.edit', $handle),
            'addSubscriberUrl' => cp_route('marketing.lists.subscribers.store', $handle),
            'canManageSubscribers' => $this->userCan($request, 'manage marketing subscribers'),
            'canManage' => $this->userCan($request, 'manage marketing lists'),
            'frequencyCap' => [
                'enabled' => $cap->enabled(),
                'max' => $cap->limit(),
                'window_hours' => $cap->windowHours(),
            ],
        ]);
    }

    /**
     * Where each subscriber on this page stands against the frequency cap.
     *
     * This is the answer to "why did this person not get the campaign". A
     * capped message is deferred and then discarded, and without something to
     * read here that is invisible: the campaign report shows a status nobody
     * can trace back to a person, and the person's own row says only
     * "subscribed". So each row carries the count inside the current window,
     * whether that count has reached the limit, and how many messages have
     * actually been held back from them.
     *
     * Two queries for the whole page, not two per row. The counts are grouped
     * by the same normalized address the cap counts on, so the number shown is
     * the number the cap used — a per-list count would disagree with it for
     * anybody on more than one list, which is precisely the case the cap
     * exists for.
     *
     * @param  Collection<int, Subscription>  $subscriptions
     * @return array<int, array{sent: int, limit: int, window_hours: int, at_limit: bool, held_back: int}>
     */
    protected function frequencyFor($subscriptions): array
    {
        $cap = app(FrequencyCap::class);

        if (! $cap->enabled() || $subscriptions->isEmpty()) {
            return [];
        }

        $emails = $subscriptions->pluck('email_normalized')->filter()->unique()->values();

        $sent = MailLogEntry::query()
            // Brand-filtered, exactly as DatabaseFrequencyCap::countInWindow()
            // filters. Without it an address that exists in two brands would be
            // shown a sum the cap never used — and this column's whole job is to
            // explain a hold, so a number that is not the one the cap acted on
            // is worse than no column at all. `MailLogEntry` carries no brand
            // scope of its own on purpose (a queue worker has no brand), so the
            // filter has to be stated here.
            ->where('brand_id', app('brand-context')->currentId())
            ->whereIn('email_normalized', $emails)
            ->where('mail_class', MailClass::Marketing->value)
            ->where('sent_at', '>=', now()->subHours($cap->windowHours()))
            ->groupBy('email_normalized')
            ->selectRaw('email_normalized, count(*) as aggregate')
            ->pluck('aggregate', 'email_normalized');

        $heldBack = Message::query()
            ->whereIn('subscription_id', $subscriptions->pluck('id'))
            ->where('status', Message::STATUS_CAPPED)
            ->groupBy('subscription_id')
            ->selectRaw('subscription_id, count(*) as aggregate')
            ->pluck('aggregate', 'subscription_id');

        return $subscriptions->mapWithKeys(function (Subscription $subscription) use ($cap, $sent, $heldBack) {
            $count = (int) ($sent[(string) $subscription->email_normalized] ?? 0);

            return [$subscription->id => [
                'sent' => $count,
                'limit' => $cap->limit(),
                'window_hours' => $cap->windowHours(),
                'at_limit' => $count >= $cap->limit(),
                'held_back' => (int) ($heldBack[$subscription->id] ?? 0),
            ]];
        })->all();
    }

    public function edit(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing lists');

        $list = $this->lists->find($handle);
        abort_unless($list, 404);

        return Inertia::render('marketing::Lists/Edit', [
            'list' => $list->toArray(),
            'updateUrl' => cp_route('marketing.lists.update', $handle),
            'deleteUrl' => cp_route('marketing.lists.destroy', $handle),
            'defaultDoubleOptIn' => (bool) config('marketing.subscriptions.double_opt_in', true),
        ]);
    }

    public function update(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing lists');

        $list = $this->lists->find($handle);
        abort_unless($list, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'double_opt_in' => ['nullable', 'boolean'],
        ]);

        $list->name = $data['name'];
        $list->description = $data['description'] ?? null;
        $list->doubleOptIn = $data['double_opt_in'] ?? null;

        $this->lists->save($list);

        return back()->with('success', __('marketing::lists.flashes.updated'));
    }

    public function destroy(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing lists');

        abort_unless($this->lists->find($handle), 404);

        $this->lists->delete($handle);

        return redirect()
            ->to(cp_route('marketing.lists.index'))
            ->with('success', __('marketing::lists.flashes.deleted'));
    }
}
