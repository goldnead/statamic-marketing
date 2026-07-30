<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Services\SubscriptionPreferences;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * The preference centre, addressed by the same token the unsubscribe link
 * carries.
 *
 * There is no login here and there will not be one. Almost no subscriber has
 * an account, and a page somebody has to register for in order to leave a
 * mailing list is a dark pattern with a password form in front of it. The
 * token is the credential, and its limits are enforced in
 * SubscriptionPreferences rather than in the template.
 */
class PreferencesController extends Controller
{
    public function show(string $token, SubscriptionPreferences $preferences)
    {
        $center = $preferences->forToken($token);

        // Identical to the answer an invented token gets, and identical to the
        // answer another brand's token gets — the brand middleware simply
        // never finds the row. A stranger cannot tell the three apart.
        abort_unless($center, 404);

        return response()->view('marketing::preferences', ['center' => $center]);
    }

    public function update(string $token, Request $request, SubscriptionPreferences $preferences)
    {
        $center = $preferences->forToken($token);

        abort_unless($center, 404);

        $data = $request->validate([
            'action' => ['nullable', Rule::in(['save', 'unsubscribe_all'])],
            'lists' => ['array'],
            'lists.*' => ['string', 'max:255'],
        ]);

        $back = redirect()->route('marketing.preferences', $token);

        if (($data['action'] ?? 'save') === 'unsubscribe_all') {
            $ended = $preferences->unsubscribeFromEverything($center);

            return $back->with('marketing.status', trans_choice(
                'marketing::public.preferences_all_done',
                count($ended),
                ['count' => count($ended)],
            ));
        }

        $result = $preferences->apply($center, $data['lists'] ?? []);

        // Every refusal is said out loud. A page that silently redraws itself
        // without the change the reader asked for is the failure mode 1.5.3
        // took out of the control panel, and it is worse out here: nobody is
        // going to file a ticket about a newsletter, they will file a spam
        // complaint.
        $errors = [];

        foreach ($result['refused'] as $handle) {
            $errors['lists.'.$handle] = __('marketing::public.preferences_error_blocked');
        }

        if ($result['unknown'] !== []) {
            $errors['lists'] = __('marketing::public.preferences_error_unknown');
        }

        $changed = count($result['subscribed']) + count($result['unsubscribed']);

        return $back
            ->withErrors($errors)
            ->with('marketing.status', trans_choice(
                'marketing::public.preferences_saved',
                $changed,
                ['count' => $changed],
            ));
    }
}
