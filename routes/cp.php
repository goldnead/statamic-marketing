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
        Route::get('/{handle}/edit', [CampaignController::class, 'edit'])->name('edit');
        Route::patch('/{handle}', [CampaignController::class, 'update'])->name('update');
        Route::delete('/{handle}', [CampaignController::class, 'destroy'])->name('destroy');

        Route::post('/{handle}/send', [CampaignController::class, 'send'])->name('send');
        Route::post('/{handle}/schedule', [CampaignController::class, 'schedule'])->name('schedule');
        Route::post('/{handle}/unschedule', [CampaignController::class, 'unschedule'])->name('unschedule');
        Route::post('/{handle}/test', [CampaignController::class, 'sendTest'])->name('test');
        Route::get('/{handle}/preview', [CampaignController::class, 'preview'])->name('preview');
    });

    Route::prefix('templates')->name('templates.')->group(function () {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::get('/create', [TemplateController::class, 'create'])->name('create');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::get('/{handle}/edit', [TemplateController::class, 'edit'])->name('edit');
        Route::patch('/{handle}', [TemplateController::class, 'update'])->name('update');
        Route::delete('/{handle}', [TemplateController::class, 'destroy'])->name('destroy');
    });
});
