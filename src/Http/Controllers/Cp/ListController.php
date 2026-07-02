<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Support\Str;

class ListController extends Controller
{
    public function __construct(protected MailingListRepository $lists)
    {
    }

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

        $rows = collect($page->items())->map(fn (Subscription $subscription) => [
            'id' => $subscription->uuid,
            'email' => $subscription->email,
            'name' => trim(($subscription->first_name ?? '').' '.($subscription->last_name ?? '')),
            'status' => $subscription->status,
            'subscribed_at' => $subscription->subscribed_at?->toIso8601String(),
            'contact_uuid' => $subscription->contact_uuid,
            'unsubscribe_url' => cp_route('marketing.lists.subscribers.unsubscribe', [$handle, $subscription->uuid]),
            'delete_url' => cp_route('marketing.lists.subscribers.destroy', [$handle, $subscription->uuid]),
        ])->all();

        $columns = collect([
            Column::make('email')->label(__('marketing::subscribers.email')),
            Column::make('name')->label(__('marketing::subscribers.name')),
            Column::make('status')->label(__('marketing::subscribers.status')),
            Column::make('subscribed_at')->label(__('marketing::subscribers.subscribed_at')),
        ])->map(fn ($c) => $c->toArray())->all();

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
        ]);
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
