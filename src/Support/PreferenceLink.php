<?php

namespace Goldnead\Marketing\Support;

use Illuminate\Support\Facades\Route;

/**
 * The one place that decides where a subscriber's link goes.
 *
 * Two addons can serve the page behind an e-mail footer. `goldnead/statamic-
 * preference-center`, when installed, owns the full preference page: every
 * list of the brand, notification types, the suppression state, one screen.
 * Marketing owns nothing more than a single-list unsubscribe, and it owns that
 * unconditionally — stopping mail is a legal obligation and may not depend on
 * an optional package being present.
 *
 * Before this class existed both addons shipped the same page and marketing
 * kept linking to its own, so installing the preference centre changed nothing
 * a reader could see: the footer still landed on the single-list page. The fix
 * is not a second link but a single resolver every call site asks.
 *
 * Two questions, deliberately kept apart:
 *
 *  - {@see manage()} is for a person. It goes to the preference centre when
 *    there is one, and to marketing's own unsubscribe page when there is not.
 *  - {@see oneClick()} is for a machine — the RFC 8058 `List-Unsubscribe`
 *    header, which a mail provider POSTs to with no session and no page. That
 *    one is *always* marketing's own endpoint. Pointing it at a preference
 *    page would mean the provider's one-click button renders a form instead of
 *    unsubscribing, which is the failure RFC 8058 exists to prevent.
 *
 * ## How the sibling is detected
 *
 * `class_exists()` on the sibling's facade, and then the route registry.
 *
 * The registry check is not belt-and-braces. The preference centre registers
 * its token route conditionally — only where marketing is installed, because
 * the brand middleware on it names one of marketing's models. So the class can
 * be present while the route is not, and `route()` on a name that was never
 * registered throws. Asking the registry asks the question that actually
 * matters: is there somewhere to send this person?
 *
 * What this must never become is `method_exists()` on the facade class.
 * Facades answer through `__callStatic`, so `method_exists(SomeFacade::class,
 * 'anything')` is false for every method the facade proxies — that mistake
 * shipped in automations 1.0.3 and disabled every LeadHub action node on real
 * installs. Where a method has to be probed, probe `getFacadeRoot()`.
 */
class PreferenceLink
{
    /**
     * The sibling's facade. Named as a string rather than imported: this addon
     * does not require the preference centre, so the class may legitimately
     * not exist and an import would read as a dependency it is not.
     */
    public const CENTER_FACADE = '\Goldnead\PreferenceCenter\Facades\PreferenceCenter';

    /** The sibling's token entrance, and its route parameter. */
    public const CENTER_ROUTE = 'preference-center.token';

    public const CENTER_PARAMETER = 'pcToken';

    /** Is there a preference centre able to answer for this token? */
    public function centerAvailable(): bool
    {
        return class_exists(self::CENTER_FACADE) && Route::has(self::CENTER_ROUTE);
    }

    /**
     * Where to send a person who wants to change what they receive.
     *
     * The preference centre when it is installed, marketing's own unsubscribe
     * page otherwise. Both are addressed by the same subscription token, which
     * is what makes the swap invisible to every caller.
     */
    public function manage(string $token): string
    {
        if ($this->centerAvailable()) {
            return route(self::CENTER_ROUTE, [self::CENTER_PARAMETER => $token]);
        }

        return $this->oneClick($token);
    }

    /**
     * Marketing's own unsubscribe endpoint. Never the preference centre.
     *
     * The GET and the POST share this URL — the GET unsubscribes and shows a
     * page, the POST unsubscribes and answers 204 — which is what lets one
     * string serve both the footer fallback and the `List-Unsubscribe` header.
     */
    public function oneClick(string $token): string
    {
        return route('marketing.unsubscribe', ['token' => $token]);
    }
}
