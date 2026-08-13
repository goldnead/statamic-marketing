<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Exceptions\ConfirmationLinkExpired;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Routing\Controller;

/**
 * The double-opt-in landing.
 *
 * Split into a GET that shows and a POST that acts, because a GET is not
 * something the recipient necessarily did. Outlook SafeLinks, a virus scanner
 * on the mail gateway and a messenger's link preview all fetch every URL in an
 * incoming message, and under the old single-GET design each of those fetches
 * granted the consent. That produced subscriptions nobody had agreed to, and —
 * worse for a record that has to be defensible — it produced them with a
 * timestamp and an IP that look exactly like a real confirmation.
 *
 * The cost is one click. `marketing.subscriptions.confirm_requires_post` turns
 * it off for installs that would rather have the one-click flow back, and the
 * flag is the only reason the old behaviour still exists in this file.
 */
class ConfirmController extends Controller
{
    /**
     * Show the link's state. Changes nothing, whoever or whatever opened it.
     */
    public function show(
        string $token,
        SubscriptionService $subscriptions,
        MailingListRepository $lists,
    ) {
        if (! config('marketing.subscriptions.confirm_requires_post', true)) {
            return $this->store($token, $subscriptions, $lists);
        }

        $subscription = $subscriptions->findByConfirmationToken($token);

        abort_unless($subscription !== null, 404);

        /*
         * The ladder below only decides which page to render. It is not the
         * guard — `confirmByToken()` re-derives every one of these conditions
         * when the button is pressed, and it is the authority. The worst this
         * can get wrong is showing a button whose POST then refuses, which is
         * a cosmetic error rather than a hole.
         */
        if ($subscription->confirmation_used_at !== null) {
            abort_unless($subscription->isSubscribed(), 404);

            return $this->confirmed($subscription, $lists);
        }

        abort_unless($subscription->status === Subscription::STATUS_PENDING, 404);

        // Never mailed, so not a link anybody can be holding.
        abort_unless($subscription->confirmation_sent_at !== null, 404);

        if ($subscription->confirmationHasExpired()) {
            return $this->expired();
        }

        return response()->view('marketing::confirm', [
            'subscription' => $subscription,
            'list' => $lists->find($subscription->list_handle),
            'token' => $token,
        ]);
    }

    /**
     * Grant the consent. Only ever reached by something that pressed a button.
     */
    public function store(
        string $token,
        SubscriptionService $subscriptions,
        MailingListRepository $lists,
    ) {
        try {
            $subscription = $subscriptions->confirmByToken($token);
        } catch (ConfirmationLinkExpired) {
            return $this->expired();
        }

        abort_unless($subscription !== null, 404);

        return $this->confirmed($subscription, $lists);
    }

    protected function confirmed(Subscription $subscription, MailingListRepository $lists)
    {
        return response()->view('marketing::confirmed', [
            'subscription' => $subscription,
            'list' => $lists->find($subscription->list_handle),
        ]);
    }

    /**
     * 410 rather than 404: the link was real, it is simply over. The status
     * code is the honest one and it keeps expired links out of the "broken
     * site" bucket in any log anybody reads later.
     */
    protected function expired()
    {
        return response()->view('marketing::confirm-expired', [], 410);
    }
}
