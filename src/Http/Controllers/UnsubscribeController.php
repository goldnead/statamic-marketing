<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Routing\Controller;

class UnsubscribeController extends Controller
{
    public function show(
        string $token,
        SubscriptionService $subscriptions,
        MailingListRepository $lists,
    ) {
        $subscription = $subscriptions->unsubscribeByToken($token, ['reason' => 'link']);

        abort_unless($subscription, 404);

        return response()->view('marketing::unsubscribed', [
            'subscription' => $subscription,
            'list' => $lists->find($subscription->list_handle),
        ]);
    }

    /** RFC 8058 one-click unsubscribe — mail clients POST with no body context. */
    public function store(string $token, SubscriptionService $subscriptions)
    {
        $subscription = $subscriptions->unsubscribeByToken($token, ['reason' => 'one_click']);

        abort_unless($subscription, 404);

        return response()->noContent();
    }
}
