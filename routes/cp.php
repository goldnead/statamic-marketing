<?php

use Goldnead\Marketing\Http\Controllers\Cp\CampaignController;
use Goldnead\Marketing\Http\Controllers\Cp\DashboardController;
use Goldnead\Marketing\Http\Controllers\Cp\ListController;
use Goldnead\Marketing\Http\Controllers\Cp\SubscriberController;
use Goldnead\Marketing\Http\Controllers\Cp\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('marketing')->name('marketing.')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('lists')->name('lists.')->group(function () {
        Route::get('/', [ListController::class, 'index'])->name('index');
        Route::get('/create', [ListController::class, 'create'])->name('create');
        Route::post('/', [ListController::class, 'store'])->name('store');
        Route::get('/{handle}', [ListController::class, 'show'])->name('show');
        Route::get('/{handle}/edit', [ListController::class, 'edit'])->name('edit');
        Route::patch('/{handle}', [ListController::class, 'update'])->name('update');
        Route::delete('/{handle}', [ListController::class, 'destroy'])->name('destroy');

        Route::post('/{handle}/subscribers', [SubscriberController::class, 'store'])->name('subscribers.store');
        Route::post('/{handle}/subscribers/{subscription}/unsubscribe', [SubscriberController::class, 'unsubscribe'])->name('subscribers.unsubscribe');
        Route::delete('/{handle}/subscribers/{subscription}', [SubscriberController::class, 'destroy'])->name('subscribers.destroy');
    });

    Route::prefix('campaigns')->name('campaigns.')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
        Route::get('/create', [CampaignController::class, 'create'])->name('create');
        Route::post('/', [CampaignController::class, 'store'])->name('store');
        Route::get('/{handle}', [CampaignController::class, 'show'])->name('show');
        // The rows of one report tab as a CSV. Its own route rather than a
        // format on `show`, because it is a different permission: reading the
        // report is `view marketing`, taking a file of addresses off the server
        // is `manage marketing campaigns`. Which tab, and which filter, travel
        // in the query string — the same ones the screen was showing.
        Route::get('/{handle}/export', [CampaignController::class, 'export'])->name('export');
        Route::get('/{handle}/edit', [CampaignController::class, 'edit'])->name('edit');
        Route::patch('/{handle}', [CampaignController::class, 'update'])->name('update');
        Route::delete('/{handle}', [CampaignController::class, 'destroy'])->name('destroy');

        Route::post('/{handle}/send', [CampaignController::class, 'send'])->name('send');
        Route::post('/{handle}/schedule', [CampaignController::class, 'schedule'])->name('schedule');
        Route::post('/{handle}/unschedule', [CampaignController::class, 'unschedule'])->name('unschedule');
        Route::post('/{handle}/test', [CampaignController::class, 'sendTest'])->name('test');
        Route::get('/{handle}/preview', [CampaignController::class, 'preview'])->name('preview');

        // Separate from `update` on purpose: a sent campaign is no longer
        // editable, and whether it belongs in the public archive is decided
        // after it went out. See CampaignController::archive().
        Route::patch('/{handle}/archive', [CampaignController::class, 'archive'])->name('archive');
    });

    Route::prefix('templates')->name('templates.')->group(function () {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::get('/create', [TemplateController::class, 'create'])->name('create');
        // Before `/{handle}` in every sense that matters: the preview is a POST
        // and the wildcards here are GET/PATCH/DELETE, but keeping it up with
        // `create` states that it is not a template of its own.
        Route::post('/preview', [TemplateController::class, 'preview'])->name('preview');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::get('/{handle}/edit', [TemplateController::class, 'edit'])->name('edit');
        Route::patch('/{handle}', [TemplateController::class, 'update'])->name('update');
        Route::delete('/{handle}', [TemplateController::class, 'destroy'])->name('destroy');
    });
});
