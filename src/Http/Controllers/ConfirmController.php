<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Routing\Controller;

class ConfirmController extends Controller
{
    public function __invoke(
        string $token,
        SubscriptionService $subscriptions,
        MailingListRepository $lists,
    ) {
        $subscription = $subscriptions->confirmByToken($token);

        abort_unless($subscription, 404);

        $list = $lists->find($subscription->list_handle);

        return response()->view('marketing::confirmed', [
            'subscription' => $subscription,
            'list' => $list,
        ]);
    }
}
