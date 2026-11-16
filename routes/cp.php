<?php

use Goldnead\BrandContext\Http\Middleware\GuardSetup;
use Goldnead\Marketing\Http\Controllers\Cp\CampaignController;
use Goldnead\Marketing\Http\Controllers\Cp\DashboardController;
use Goldnead\Marketing\Http\Controllers\Cp\ListController;
use Goldnead\Marketing\Http\Controllers\Cp\SequenceController;
use Goldnead\Marketing\Http\Controllers\Cp\SubscriberController;
use Goldnead\Marketing\Http\Controllers\Cp\TemplateController;
use Goldnead\Marketing\Support\Setup;
use Illuminate\Support\Facades\Route;

/*
 * The setup screen for the detail pages, one guard per controller group. Each
 * names the same tables as that controller's index() passes to Setup::guard(),
 * so a detail page and its listing give the same answer. The definition tables
 * are read from the config here, while the routes load, which is after the
 * config is in place.
 *
 * Subscribers belong to the list they subscribe to, so they take the lists guard.
 */
$lists = GuardSetup::using(Setup::class, 'marketing::nav.lists', 'marketing_subscriptions', ...Setup::definitionTables('marketing_lists'));
$campaigns = GuardSetup::using(Setup::class, 'marketing::nav.campaigns', 'marketing_messages', 'marketing_message_events', ...Setup::definitionTables('marketing_campaigns'));
$sequences = GuardSetup::using(Setup::class, 'marketing::nav.sequences', 'marketing_sequences', 'marketing_sequence_steps');
$templates = GuardSetup::using(Setup::class, 'marketing::nav.templates', ...Setup::definitionTables('marketing_templates'));

Route::prefix('marketing')->name('marketing.')->group(function () use ($lists, $campaigns, $sequences, $templates) {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('lists')->name('lists.')->group(function () use ($lists) {
        Route::get('/', [ListController::class, 'index'])->name('index');
        Route::get('/create', [ListController::class, 'create'])->name('create');
        Route::post('/', [ListController::class, 'store'])->name('store');
        // Kein `/{handle}/edit`: die Detailseite ist das Formular. Eine zweite
        // Seite mit denselben drei Feldern waere eine zweite Stelle, an der die
        // naechste Aenderung gemacht oder vergessen wird.
        Route::get('/{handle}', [ListController::class, 'show'])->name('show')->middleware($lists);
        Route::patch('/{handle}', [ListController::class, 'update'])->name('update')->middleware($lists);
        Route::delete('/{handle}', [ListController::class, 'destroy'])->name('destroy')->middleware($lists);

        Route::post('/{handle}/subscribers', [SubscriberController::class, 'store'])->name('subscribers.store')->middleware($lists);
        Route::post('/{handle}/subscribers/{subscription}/unsubscribe', [SubscriberController::class, 'unsubscribe'])->name('subscribers.unsubscribe')->middleware($lists);
        Route::delete('/{handle}/subscribers/{subscription}', [SubscriberController::class, 'destroy'])->name('subscribers.destroy')->middleware($lists);
    });

    Route::prefix('campaigns')->name('campaigns.')->group(function () use ($campaigns) {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
        Route::get('/create', [CampaignController::class, 'create'])->name('create');

        /*
         * Vor `/{handle}` in jedem Sinn, der zaehlt: die Live-Vorschau ist ein
         * POST auf einen festen Pfad und darf nicht als Handle „preview"
         * gelesen werden. Dasselbe Muster wie bei den Vorlagen weiter unten.
         */
        Route::post('/preview', [CampaignController::class, 'livePreview'])->name('live-preview');
        Route::post('/', [CampaignController::class, 'store'])->name('store');
        Route::get('/{handle}', [CampaignController::class, 'show'])->name('show')->middleware($campaigns);
        // The rows of one report tab as a CSV. Its own route rather than a
        // format on `show`, because it is a different permission: reading the
        // report is `view marketing`, taking a file of addresses off the server
        // is `manage marketing campaigns`. Which tab, and which filter, travel
        // in the query string — the same ones the screen was showing.
        Route::get('/{handle}/export', [CampaignController::class, 'export'])->name('export')->middleware($campaigns);
        Route::get('/{handle}/edit', [CampaignController::class, 'edit'])->name('edit')->middleware($campaigns);
        Route::patch('/{handle}', [CampaignController::class, 'update'])->name('update')->middleware($campaigns);
        Route::delete('/{handle}', [CampaignController::class, 'destroy'])->name('destroy')->middleware($campaigns);

        Route::post('/{handle}/send', [CampaignController::class, 'send'])->name('send')->middleware($campaigns);
        Route::post('/{handle}/schedule', [CampaignController::class, 'schedule'])->name('schedule')->middleware($campaigns);
        Route::post('/{handle}/unschedule', [CampaignController::class, 'unschedule'])->name('unschedule')->middleware($campaigns);
        Route::post('/{handle}/approve', [CampaignController::class, 'approve'])->name('approve')->middleware($campaigns);
        Route::post('/{handle}/withdraw', [CampaignController::class, 'withdraw'])->name('withdraw')->middleware($campaigns);
        Route::post('/{handle}/test', [CampaignController::class, 'sendTest'])->name('test')->middleware($campaigns);
        Route::get('/{handle}/preview', [CampaignController::class, 'preview'])->name('preview')->middleware($campaigns);

        // Separate from `update` on purpose: a sent campaign is no longer
        // editable, and whether it belongs in the public archive is decided
        // after it went out. See CampaignController::archive().
        Route::patch('/{handle}/archive', [CampaignController::class, 'archive'])->name('archive')->middleware($campaigns);
    });

    Route::prefix('sequences')->name('sequences.')->group(function () use ($sequences) {
        Route::get('/', [SequenceController::class, 'index'])->name('index');
        Route::get('/create', [SequenceController::class, 'create'])->name('create');
        Route::post('/', [SequenceController::class, 'store'])->name('store');
        Route::get('/{handle}/edit', [SequenceController::class, 'edit'])->name('edit')->middleware($sequences);
        Route::patch('/{handle}', [SequenceController::class, 'update'])->name('update')->middleware($sequences);
        Route::delete('/{handle}', [SequenceController::class, 'destroy'])->name('destroy')->middleware($sequences);
    });

    Route::prefix('templates')->name('templates.')->group(function () use ($templates) {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::get('/create', [TemplateController::class, 'create'])->name('create');
        // Before `/{handle}` in every sense that matters: the preview is a POST
        // and the wildcards here are GET/PATCH/DELETE, but keeping it up with
        // `create` states that it is not a template of its own.
        Route::post('/preview', [TemplateController::class, 'preview'])->name('preview');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::get('/{handle}/edit', [TemplateController::class, 'edit'])->name('edit')->middleware($templates);
        Route::patch('/{handle}', [TemplateController::class, 'update'])->name('update')->middleware($templates);
        Route::delete('/{handle}', [TemplateController::class, 'destroy'])->name('destroy')->middleware($templates);
    });
});
