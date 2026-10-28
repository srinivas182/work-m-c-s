<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Payments\Http\Controllers\Admin\PaymentMethodsController;
use Wurk\Payments\Http\Controllers\PaymentSettingsController;
use Wurk\Payments\Http\Controllers\PublicPaymentsController;
use Wurk\Payments\Http\Controllers\WurkPaymentsController;
use Wurk\Payments\Support\PlatformMethods;

// Payments to WURK (on WURK's app address) — registered first: the business pattern {handle}.<root> would also match app.<root>.
Route::domain((string) config('wurk.domains.app'))->withoutMiddleware(['web'])->name('payments.wurk.')->group(function (): void {
    Route::get('/pay/{reference}', [WurkPaymentsController::class, 'pay'])->middleware(['signed', 'throttle:payments-handoff'])->name('pay');
    Route::post('/payments/notify/{provider}', [WurkPaymentsController::class, 'notify'])->middleware('throttle:payments-notify')->name('notify');
});

// On the business's own address: PayFast's hand-off page (signed link) and provider notifications.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->name('payments.public.')->group(function (): void {
    Route::get('/pay/{reference}', [PublicPaymentsController::class, 'pay'])->middleware(['signed', 'throttle:payments-handoff'])->name('pay');
    Route::post('/payments/notify/{provider}', [PublicPaymentsController::class, 'notify'])->middleware('throttle:payments-notify')->name('notify');
});

Route::tenant(function (): void {
    Route::middleware(['permission:payments.manage'])->prefix('settings/payments')->name('tenant.payments.')->group(function (): void {
        Route::get('/', [PaymentSettingsController::class, 'show'])->name('settings');
        Route::put('/{provider}/keys/{mode}', [PaymentSettingsController::class, 'keys'])->whereIn('provider', PlatformMethods::ALL)->whereIn('mode', ['test', 'live'])->middleware('throttle:20,1,payments-keys')->name('keys');
        Route::put('/{provider}', [PaymentSettingsController::class, 'settings'])->whereIn('provider', PlatformMethods::ALL)->name('method');
        Route::delete('/{provider}', [PaymentSettingsController::class, 'disconnect'])->whereIn('provider', PlatformMethods::ALL)->name('disconnect');
    });
});

// Super admin → Payment methods.
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->prefix('payment-methods')->name('admin.payment-methods')->group(function (): void {
    Route::get('/', [PaymentMethodsController::class, 'index']);
    Route::put('/{provider}/available', [PaymentMethodsController::class, 'availability'])->whereIn('provider', PlatformMethods::ALL)->name('.available');
    Route::put('/{provider}/keys/{mode}', [PaymentMethodsController::class, 'keys'])->whereIn('provider', PlatformMethods::ALL)->whereIn('mode', ['test', 'live'])->middleware('throttle:20,1,admin-payment-keys')->name('.keys');
    Route::put('/{provider}', [PaymentMethodsController::class, 'settings'])->whereIn('provider', PlatformMethods::ALL)->name('.settings');
});
