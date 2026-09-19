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
    /**
     * Show the link's state. Changes nothing, whoever or whatever opened it.
     *
     * A GET is not something the reader necessarily did. Outlook SafeLinks, the
     * virus scanner on a mail gateway and a messenger's link preview all fetch
     * every URL in an incoming message, and while this method unsubscribed, each
     * of those fetches ended somebody's subscription without their knowledge —
     * recorded with a timestamp that looks exactly like a real click. Measured
     * on staging on 18.09.2026: one page view, status `unsubscribed`.
     *
     * The sibling question was already decided this way for the double opt-in
     * ({@see ConfirmController}); `confirm_requires_post` is its switch. The
     * argument holds here the other way round, so this has the same switch:
     * `marketing.unsubscribe.requires_post`.
     *
     * What does NOT change: the RFC 8058 one-click POST that Google and Yahoo
     * require. Their robots send `List-Unsubscribe=One-Click` and get 204.
     */
    public function show(
        string $token,
        SubscriptionService $subscriptions,
        MailingListRepository $lists,
        PreferenceLink $links,
    ) {
        if (! config('marketing.unsubscribe.requires_post', true)) {
            // Nicht ueber store(): dort entscheidet der Seiten-Marker ueber die
            // Antwort, und den hat ein Aufruf aus dem Mailprogramm nicht. Wer
            // diesen Rueckweg einschaltet, will die alte Seite sehen, nicht 204.
            $abgemeldet = $subscriptions->unsubscribeByToken($token, ['reason' => 'link']);

            abort_if($abgemeldet === null, 404);

            return $this->abgemeldeteSeite($abgemeldet, $token, $lists, $links);
        }

        $subscription = $subscriptions->findByToken($token);

        abort_if($subscription === null, 404);

        // Already gone: say so rather than offering a button that ends nothing.
        if (! $subscription->isSubscribed()) {
            return $this->abgemeldeteSeite($subscription, $token, $lists, $links);
        }

        return response()->view('marketing::unsubscribe-confirm', [
            'subscription' => $subscription,
            'list' => $lists->find($subscription->list_handle),
            'token' => $token,
        ]);
    }

    /**
     * End the subscription. Reached by a robot's one-click POST or by the button.
     *
     * The two are told apart by a marker the OWN page sends, not by one the
     * robots are expected to send. RFC 8058 does prescribe a
     * `List-Unsubscribe=One-Click` body, and a provider that follows it could
     * be recognised by that — but a provider that does not would then be handed
     * an HTML page where it expects 204, and unsubscribing is the one path that
     * may not become fussy. So anything without this page's own marker is
     * treated as a robot and answered exactly as before.
     */
    public function store(
        string $token,
        SubscriptionService $subscriptions,
        MailingListRepository $lists,
        PreferenceLink $links,
    ) {
        $vonDerSeite = request()->input('via') === 'page';

        $subscription = $subscriptions->unsubscribeByToken($token, [
            'reason' => $vonDerSeite ? 'link' : 'one_click',
        ]);

        abort_if($subscription === null, 404);

        return $vonDerSeite
            ? $this->abgemeldeteSeite($subscription, $token, $lists, $links)
            : response()->noContent();
    }

    protected function abgemeldeteSeite($subscription, string $token, MailingListRepository $lists, PreferenceLink $links)
    {
        return response()->view('marketing::unsubscribed', [
            'subscription' => $subscription,
            'list' => $lists->find($subscription->list_handle),
            // Null on a bare install, and the template then says nothing about
            // preferences at all. Offering a door that is not there was the
            // other half of the duplication.
            'preferencesUrl' => $links->centerAvailable() ? $links->manage($token) : null,
        ]);
    }
}
