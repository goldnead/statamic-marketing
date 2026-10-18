<?php

use Goldnead\BrandContext\Http\Middleware\SetBrandFromRouteValue;
use Goldnead\Marketing\Http\Controllers\ArchiveController;
use Goldnead\Marketing\Http\Controllers\ConfirmController;
use Goldnead\Marketing\Http\Controllers\LettermintWebhookController;
use Goldnead\Marketing\Http\Controllers\RatingController;
use Goldnead\Marketing\Http\Controllers\SubscribeController;
use Goldnead\Marketing\Http\Controllers\TagLinkController;
use Goldnead\Marketing\Http\Controllers\TrackingController;
use Goldnead\Marketing\Http\Controllers\UnsubscribeController;
use Goldnead\Marketing\Http\Middleware\SetBrandFromListHandle;
use Goldnead\Marketing\Http\Middleware\ValidateTrackingSignature;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Rating;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\HandleOwnership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 * Public routes. Every one of these is opened without a session — by a mail
 * client, by a stranger's browser, by a provider's unsubscribe robot. Under
 * multi-brand that means no brand is current, the fail-closed scope hides the
 * very record the link points at, and the link 404s. The brand is therefore
 * derived from the value the visitor already carries. Each of these values
 * addresses exactly one record across all brands, which is exactly what makes
 * that derivation safe rather than a hole; see SetBrandFromRouteValue.
 */
$brandFrom = fn (string $model, string $column, string $parameter) => SetBrandFromRouteValue::class.":{$model},{$column},{$parameter}";

Route::prefix(config('marketing.routes.prefix', '!/marketing'))->group(function () use ($brandFrom) {
    // The form names its list, and a list handle belongs to exactly one brand.
    // Lists are the one definition entity a public request addresses, and
    // definitions are not always database rows — under the flat driver a list
    // is a file. So this one derivation goes through the addon's own
    // middleware, which answers for both storage drivers with the same
    // guarantees (one owner, throw on two, silence on none).
    Route::post('/subscribe', [SubscribeController::class, 'store'])
        ->name('marketing.subscribe')
        ->middleware(SetBrandFromListHandle::class.':list');

    /*
     * Confirmation is two routes, and the split is the point: a GET shows the
     * link's state, a POST grants the consent. Mail gateways, virus scanners
     * and messenger previews fetch every URL in an incoming message, and under
     * a single GET each of those fetches subscribed somebody who had not yet
     * decided. See ConfirmController.
     *
     * The brand is derived from `confirmation_token`, not `token`. Both
     * address exactly one row across all brands, which is what makes the
     * derivation safe; the confirmation token is the one this route carries.
     */
    Route::get('/confirm/{token}', [ConfirmController::class, 'show'])
        ->name('marketing.confirm')
        ->middleware($brandFrom(Subscription::class, 'confirmation_token', 'token'));

    // Same page's button. CSRF applies normally — unlike the one-click
    // unsubscribe below, this POST is only ever made by a browser that has
    // just been served the form, never by a provider's robot.
    Route::post('/confirm/{token}', [ConfirmController::class, 'store'])
        ->name('marketing.confirm.post')
        ->middleware($brandFrom(Subscription::class, 'confirmation_token', 'token'));

    Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
        ->name('marketing.unsubscribe')
        ->middleware($brandFrom(Subscription::class, 'token', 'token'));

    /*
     * There is no preference route here any more.
     *
     * Marketing used to serve a multi-list preference centre of its own, and
     * `goldnead/statamic-preference-center` serves the same page over
     * marketing, notifications and suppression together. Two addons rendering
     * one page is not redundancy, it is a fork: marketing kept linking to its
     * own, so installing the preference centre left every footer link on the
     * single-list page. The page now belongs to that addon alone.
     *
     * What stays here is the one thing that may not depend on an optional
     * package: unsubscribing. `GET /unsubscribe/{token}` ends the subscription
     * and says so, `POST` does the same for a provider's one-click button.
     * Where to send a reader who wants more than that is answered in one
     * place, `Support\PreferenceLink`, which points at the preference centre
     * when it is installed and here when it is not.
     */

    // RFC 8058 one-click unsubscribe (List-Unsubscribe-Post). Mail providers
    // POST here themselves, with no session and no CSRF token, so the forgery
    // check has to be excluded. Laravel has renamed that middleware and
    // applications sometimes subclass it, so all three names are listed:
    // excluding a class that is not in the stack costs nothing, while missing
    // the one that is turns every one-click unsubscribe into a 419 — which is
    // exactly what happened here.
    Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
        ->name('marketing.unsubscribe.post')
        ->middleware($brandFrom(Subscription::class, 'token', 'token'))
        ->withoutMiddleware([
            PreventRequestForgery::class,
            ValidateCsrfToken::class,
            'App\Http\Middleware\VerifyCsrfToken',
        ]);

    /*
     * Ein-Klick-Bewertung der Ausgabe (Gut / In Ordnung / Schlecht), aus dem
     * Mailfuss. Zwei Routen, und die Trennung ist der Sinn: der GET zaehlt die
     * Wahl sofort und zeigt die Danke-Seite, der POST nimmt den freiwilligen
     * Satz dazu entgegen. Beide sind signiert wie der Klick-Zaehler, also mit
     * derselben Nachsicht gegenueber Zusatzparametern eines Versanddienstes
     * (`ValidateTrackingSignature`); die Wahl und die Zustellung stehen im
     * signierten Pfad und lassen sich nicht austauschen. Die Marke kommt wie
     * beim Klick aus der Zustellung. Der POST laeuft mit CSRF: er kommt nur aus
     * der Seite, die der GET gerade ausgeliefert hat.
     */
    // `marketingChoice`, nicht `choice`: Parameternamen gelten anwendungsweit
    // (siehe `{marketingCampaign}` unten), und das Wort ist eines, das ein
    // Geschwister-Addon selbst binden koennte.
    Route::get('/feedback/{uuid}/{marketingChoice}', [RatingController::class, 'show'])
        ->name('marketing.rate')
        ->where('marketingChoice', implode('|', Rating::CHOICES))
        ->middleware([
            ValidateTrackingSignature::class,
            $brandFrom(Message::class, 'uuid', 'uuid'),
        ]);

    Route::post('/feedback/{uuid}', [RatingController::class, 'reason'])
        ->name('marketing.rate.reason')
        ->middleware([
            ValidateTrackingSignature::class,
            $brandFrom(Message::class, 'uuid', 'uuid'),
        ]);

    /*
     * Tag links (`{{ marketing_tag_link:key }}`). The same split as the
     * confirmation above: the GET shows a page and changes nothing, the POST
     * sets the tags. Both are signed, with the mail-platform tolerance of the
     * click counter, and both address the one subscription in `s`, which is
     * where the brand comes from. The POST keeps CSRF: it only ever comes from
     * the page the GET just served.
     *
     * One URL for both verbs, so the page can post to the address it was
     * opened on and the signature still holds. `marketingTagKey` and not `key`
     * for the usual reason: parameter names are application-wide.
     */
    Route::get('/tag/{marketingTagKey}', [TagLinkController::class, 'show'])
        ->name('marketing.tag-link.show')
        ->where('marketingTagKey', '[A-Za-z0-9_-]+')
        ->middleware([
            ValidateTrackingSignature::class,
            $brandFrom(Subscription::class, 'uuid', 's'),
        ]);

    Route::post('/tag/{marketingTagKey}', [TagLinkController::class, 'apply'])
        ->name('marketing.tag-link.apply')
        ->where('marketingTagKey', '[A-Za-z0-9_-]+')
        ->middleware([
            ValidateTrackingSignature::class,
            $brandFrom(Subscription::class, 'uuid', 's'),
        ]);

    // Lettermint's event webhook. Machine to machine: no session, no CSRF
    // token, and the HMAC signature is the only authentication.
    Route::post('/webhooks/lettermint', LettermintWebhookController::class)
        ->name('marketing.webhooks.lettermint')
        ->withoutMiddleware([
            PreventRequestForgery::class,
            ValidateCsrfToken::class,
            'App\Http\Middleware\VerifyCsrfToken',
        ]);

    Route::get('/o/{uuid}.gif', [TrackingController::class, 'open'])
        ->name('marketing.track.open')
        ->middleware($brandFrom(Message::class, 'uuid', 'uuid'));

    // The one signed route in this file, and the only one a sending platform
    // can break. A click counter forwards the reader with a parameter of its
    // own attached, which changes the string `signed` verifies and answers 403
    // ahead of the controller — so the reader never arrives and the click is
    // never counted. `ValidateTrackingSignature` is `signed` with the named
    // provider parameters overlooked; everything else, above all the
    // destination in `url`, stays inside the signature. `TrackingParameters`
    // says what that costs and enforces the boundary.
    Route::get('/c/{uuid}', [TrackingController::class, 'click'])
        ->name('marketing.track.click')
        ->middleware([
            ValidateTrackingSignature::class,
            $brandFrom(Message::class, 'uuid', 'uuid'),
        ]);
});

/*
 * The newsletter web archive.
 *
 * Deliberately outside the group above and without its bang prefix. Everything
 * in that group is a machine endpoint — a pixel, a redirect, a provider's
 * unsubscribe POST — and `!/` marks it as such, the way Statamic marks its own
 * action routes. These three are pages: a reader opens them, links them, and a
 * crawler indexes them, so they get a readable path of their own.
 *
 * `feed.xml` is declared before the campaign route and constrained out of it.
 * Without both, a campaign whose handle happened to be `feed` would shadow the
 * feed — or, worse, the feed URL would resolve as a campaign lookup and 404 with
 * no explanation. Handles are snake_case, so the constraint excludes nothing.
 *
 * The parameter is `{marketingCampaign}` and not `{campaign}`. Route parameter
 * names are application-wide: a sibling addon that binds `{campaign}` — and
 * `campaign` is a word half this family could reach for — would resolve ours
 * against its own repository, find nothing, and 404 every archive page. That is
 * not hypothetical, it is how goldnead/statamic-leadhub 1.8.0 shipped a delete
 * button that did nothing. The name never appears in a URL, so the cost is nil.
 */
// Registered only when the archive is on. Checking the flag inside the
// controller is not enough: the route would still exist and still win against a
// host route on the same path, so a site with its own /newsletter page lost it
// even with the archive switched off.
if (config('marketing.archive.enabled', false)) {
    Route::prefix(config('marketing.archive.prefix', 'newsletter'))->group(function () {
        Route::get('/', [ArchiveController::class, 'index'])
            ->name('marketing.archive.index');

        Route::get('/feed.xml', [ArchiveController::class, 'feed'])
            ->name('marketing.archive.feed');

        // No session, so no brand — derived from the handle, which addresses
        // exactly one campaign across all brands. Same guarantee, same middleware
        // and the same reasoning as the subscribe endpoint above.
        Route::get('/{marketingCampaign}', [ArchiveController::class, 'show'])
            ->name('marketing.archive.show')
            ->where('marketingCampaign', '[a-z0-9_]+')
            ->middleware(SetBrandFromListHandle::class.':marketingCampaign,'.HandleOwnership::CAMPAIGNS);
    });
}
