<?php

use Goldnead\BrandContext\Http\Middleware\SetBrandFromRouteValue;
use Goldnead\Marketing\Http\Controllers\ConfirmController;
use Goldnead\Marketing\Http\Controllers\SubscribeController;
use Goldnead\Marketing\Http\Controllers\TrackingController;
use Goldnead\Marketing\Http\Controllers\UnsubscribeController;
use Goldnead\Marketing\Http\Middleware\SetBrandFromListHandle;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
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

    Route::get('/confirm/{token}', ConfirmController::class)
        ->name('marketing.confirm')
        ->middleware($brandFrom(Subscription::class, 'token', 'token'));

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

    Route::get('/o/{uuid}.gif', [TrackingController::class, 'open'])
        ->name('marketing.track.open')
        ->middleware($brandFrom(Message::class, 'uuid', 'uuid'));

    Route::get('/c/{uuid}', [TrackingController::class, 'click'])
        ->name('marketing.track.click')
        ->middleware(['signed', $brandFrom(Message::class, 'uuid', 'uuid')]);
});
