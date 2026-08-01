<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Support\PreferenceLink;
use Illuminate\Routing\Controller;

/**
 * The minimal unsubscribe path, and deliberately nothing more.
 *
 * Stopping mail is a legal obligation, so it lives in the addon that sent the
 * mail and works on a bare install with no optional packages present. What it
 * no longer does is render a preference form. That page belongs to
 * `goldnead/statamic-preference-center`, which shows marketing's lists,
 * notification types and the suppression state on one screen; marketing
 * shipping a second copy of it meant every footer link stayed on the
 * single-list page even after the centre was installed.
 *
 * So this page ends one subscription, says so, and — only where a centre is
 * actually installed — offers the way to the rest. {@see PreferenceLink}.
 */
class UnsubscribeController extends Controller
{
    public function show(
        string $token,
        SubscriptionService $subscriptions,
        MailingListRepository $lists,
        PreferenceLink $links,
    ) {
        $subscription = $subscriptions->unsubscribeByToken($token, ['reason' => 'link']);

        abort_unless($subscription, 404);

        return response()->view('marketing::unsubscribed', [
            'subscription' => $subscription,
            'list' => $lists->find($subscription->list_handle),
            // Null on a bare install, and the template then says nothing about
            // preferences at all. Offering a door that is not there was the
            // other half of the duplication.
            'preferencesUrl' => $links->centerAvailable() ? $links->manage($token) : null,
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
