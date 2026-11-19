<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Reach\Http\Controllers\ReachSettingsController;
use Wurk\Reach\Http\Controllers\SmsBookingController;
use Wurk\Reach\Http\Controllers\UssdController;

// WURK's USSD code and SMS number (25.2): the gateway posts here — no session or CSRF; a secret token in the address.
Route::domain((string) config('wurk.domains.app'))
    ->withoutMiddleware(['web'])
    ->middleware('throttle:1200,1')
    ->group(function (): void {
        Route::post('/webhooks/ussd/{provider}/{token}', UssdController::class)
            ->where(['provider' => '[a-z]+', 'token' => '[A-Za-z0-9_-]{16,100}'])->name('webhooks.ussd');
        Route::post('/webhooks/sms/africastalking/{token}', SmsBookingController::class)
            ->where(['token' => '[A-Za-z0-9_-]{16,100}'])->name('webhooks.sms-booking');
    });

// Settings → Bookings by phone (25.2b).
Route::tenant(function (): void {
    Route::middleware(['permission:reach.manage'])->prefix('settings/reach')->name('tenant.reach.')->group(function (): void {
        Route::get('/', [ReachSettingsController::class, 'index'])->name('settings');
        Route::put('/', [ReachSettingsController::class, 'update'])->name('update');
        Route::get('/card', [ReachSettingsController::class, 'card'])->name('card');
    });
});
