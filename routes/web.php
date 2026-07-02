<?php

use Goldnead\Marketing\Http\Controllers\ConfirmController;
use Goldnead\Marketing\Http\Controllers\SubscribeController;
use Goldnead\Marketing\Http\Controllers\TrackingController;
use Goldnead\Marketing\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('marketing.routes.prefix', '!/marketing'))->group(function () {
    Route::post('/subscribe', [SubscribeController::class, 'store'])->name('marketing.subscribe');

    Route::get('/confirm/{token}', ConfirmController::class)->name('marketing.confirm');

    Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('marketing.unsubscribe');
    // RFC 8058 one-click unsubscribe (List-Unsubscribe-Post). Mail clients
    // POST here without a session, so CSRF must be excluded — cover both the
    // Laravel 11+ middleware and legacy app-level subclasses.
    Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
        ->name('marketing.unsubscribe.post')
        ->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            'App\Http\Middleware\VerifyCsrfToken',
        ]);

    Route::get('/o/{uuid}.gif', [TrackingController::class, 'open'])->name('marketing.track.open');
    Route::get('/c/{uuid}', [TrackingController::class, 'click'])
        ->name('marketing.track.click')
        ->middleware('signed');
});
