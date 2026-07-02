<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Http\Request;

class SubscriberController extends Controller
{
    public function __construct(
        protected MailingListRepository $lists,
        protected SubscriptionService $subscriptions,
    ) {
    }

    /** Manually add a subscriber from the CP — consent is presumed given. */
    public function store(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing subscribers');

        $list = $this->lists->find($handle);
        abort_unless($list, 404);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $subscription = $this->subscriptions->subscribe(
            $list,
            $data['email'],
            ['first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null],
            ['source' => 'cp'],
        );

        // CP additions bypass double opt-in — the editor vouches for consent.
        if ($subscription->isPending()) {
            $this->subscriptions->markSubscribed($subscription);
        }

        return back()->with('success', __('marketing::subscribers.flashes.added'));
    }

    public function unsubscribe(Request $request, string $handle, string $subscriptionUuid)
    {
        $this->authorizeOrFail($request, 'manage marketing subscribers');

        $subscription = $this->findSubscription($handle, $subscriptionUuid);

        $this->subscriptions->unsubscribe($subscription, ['reason' => 'cp']);

        return back()->with('success', __('marketing::subscribers.flashes.unsubscribed'));
    }

    public function destroy(Request $request, string $handle, string $subscriptionUuid)
    {
        $this->authorizeOrFail($request, 'manage marketing subscribers');

        $this->findSubscription($handle, $subscriptionUuid)->delete();

        return back()->with('success', __('marketing::subscribers.flashes.deleted'));
    }

    protected function findSubscription(string $handle, string $uuid): Subscription
    {
        $subscription = Subscription::forList($handle)->where('uuid', $uuid)->first();

        abort_unless($subscription, 404);

        return $subscription;
    }
}
