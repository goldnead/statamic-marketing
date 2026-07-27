<?php

use Goldnead\BrandContext\Http\Middleware\SetBrandFromRouteValue;
use Goldnead\Marketing\Http\Controllers\ConfirmController;
use Goldnead\Marketing\Http\Controllers\SubscribeController;
use Goldnead\Marketing\Http\Controllers\TrackingController;
use Goldnead\Marketing\Http\Controllers\UnsubscribeController;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Facades\Route;

/*
 * Public routes. Every one of these is opened without a session — by a mail
 * client, by a stranger's browser, by a provider's unsubscribe robot. Under
 * multi-brand that means no brand is current, the fail-closed scope hides the
 * very record the link points at, and the link 404s. The brand is therefore
 * derived from the value the visitor already carries. Each of these columns
 * holds a database-level unique index across all brands, which is exactly what
 * makes that derivation safe rather than a hole; see SetBrandFromRouteValue.
 */
$brandFrom = fn (string $model, string $column, string $parameter) => SetBrandFromRouteValue::class.":{$model},{$column},{$parameter}";

Route::prefix(config('marketing.routes.prefix', '!/marketing'))->group(function () use ($brandFrom) {
    // The form names its list, and a list handle belongs to exactly one brand.
    Route::post('/subscribe', [SubscribeController::class, 'store'])
        ->name('marketing.subscribe')
        ->middleware($brandFrom(MailingListRecord::class, 'handle', 'list'));

    Route::get('/confirm/{token}', ConfirmController::class)
        ->name('marketing.confirm')
        ->middleware($brandFrom(Subscription::class, 'token', 'token'));

    Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
        ->name('marketing.unsubscribe')
        ->middleware($brandFrom(Subscription::class, 'token', 'token'));

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
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            'App\Http\Middleware\VerifyCsrfToken',
        ]);

    Route::get('/o/{uuid}.gif', [TrackingController::class, 'open'])
        ->name('marketing.track.open')
        ->middleware($brandFrom(Message::class, 'uuid', 'uuid'));

    Route::get('/c/{uuid}', [TrackingController::class, 'click'])
        ->name('marketing.track.click')
        ->middleware(['signed', $brandFrom(Message::class, 'uuid', 'uuid')]);
});
