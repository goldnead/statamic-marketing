<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Sending\ConfirmationResult;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SubscribeController extends Controller
{
    public function store(
        Request $request,
        MailingListRepository $lists,
        SubscriptionService $subscriptions,
    ) {
        $honeypot = (string) config('marketing.subscriptions.honeypot', 'website');

        // Bots that fill the honeypot get a believable success and nothing else.
        if ($honeypot && $request->filled($honeypot)) {
            return $this->respond($request, ConfirmationResult::sent());
        }

        $data = $request->validate([
            // `filter` on top of the RFC check, because the RFC allows things
            // the mail transport does not. `opfer(kommentar)@example.com` is a
            // legal address with a comment in it; Laravel's default `email`
            // accepts it, and Symfony's Address — built as the message is
            // handed to the transport, well past any try/catch here — throws
            // on it. The result was an uncaught 500 on an anonymous endpoint
            // for an address that could never have been delivered to anyway.
            'email' => ['required', 'email:rfc,filter', 'max:255'],
            'list' => ['required', 'string'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $list = $lists->find($data['list']);

        abort_unless($list, 404);

        $subscription = $subscriptions->subscribe(
            $list,
            $data['email'],
            [
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
            ],
            ['source' => 'form'],
        );

        return $this->respond($request, $subscription->confirmation ?? ConfirmationResult::sent());
    }

    /**
     * What the visitor is told.
     *
     * Until 2.5.0 this answered with the subscription's own `status`, which is
     * `subscribed` for an address already on the list and `pending` for one
     * that is not — a membership oracle in a field nobody meant as one, on an
     * endpoint anybody may post to. It also meant a withheld mail and a sent
     * one were the same word, so a visitor was told to check an inbox that
     * would stay empty; that happened for real on 13.08.2026.
     *
     * `ConfirmationResult` is what replaces it, and it is deliberately the ONLY
     * thing that leaves here. Adding the status back "for the template" would
     * reopen the oracle, whatever the template then does with it.
     */
    protected function respond(Request $request, ConfirmationResult $confirmation)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'data' => $confirmation->toArray()]);
        }

        if ($redirect = $request->input('_redirect')) {
            return redirect()->to($redirect);
        }

        return back()->with('marketing.subscribed', $confirmation->outcome);
    }
}
