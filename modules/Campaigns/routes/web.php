<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Campaigns\Http\Controllers\CampaignsController;
use Wurk\Campaigns\Http\Controllers\CouponsController;
use Wurk\Campaigns\Http\Controllers\TrackingController;

Route::tenant(function (): void {
    Route::middleware(['entitled:marketing', 'permission:campaigns.manage'])->prefix('campaigns')->name('tenant.campaigns')->group(function (): void {
        Route::get('/', [CampaignsController::class, 'index']);
        Route::get('/new', [CampaignsController::class, 'create'])->name('.create');
        Route::get('/estimate', [CampaignsController::class, 'estimate'])->name('.estimate');
        Route::post('/new', [CampaignsController::class, 'store'])->name('.store');
        Route::get('/{campaign}', [CampaignsController::class, 'show'])->name('.show');
        Route::post('/{campaign}/test', [CampaignsController::class, 'test'])->middleware('throttle:10,10,campaign-test')->name('.test');
        Route::post('/{campaign}/send', [CampaignsController::class, 'send'])->middleware('throttle:10,10,campaign-send')->name('.send');
    });
    Route::middleware(['entitled:marketing', 'permission:campaigns.manage'])->prefix('coupons')->name('tenant.coupons')->group(function (): void {
        Route::get('/', [CouponsController::class, 'index']);
        Route::post('/', [CouponsController::class, 'store'])->name('.store');
        Route::post('/{coupon}/off', [CouponsController::class, 'switchOff'])->name('.off');
    });
});

// On the business's own site: tracked links, the open image and opting out (no session needed).
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->middleware('throttle:120,1,campaign-tracking')->group(function (): void {
    Route::get('/c/{token}/l', [TrackingController::class, 'link'])->where('token', '[a-z0-9]{32}')->name('campaigns.link');
    Route::get('/c/{token}/o.gif', [TrackingController::class, 'open'])->where('token', '[a-z0-9]{32}')->name('campaigns.open');
    Route::match(['get', 'post'], '/c/{token}/stop', [TrackingController::class, 'stop'])->where('token', '[a-z0-9]{32}')->name('campaigns.stop');
});
