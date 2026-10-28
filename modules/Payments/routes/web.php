<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Payments\Http\Controllers\PaymentSettingsController;
use Wurk\Payments\Http\Controllers\PublicPaymentsController;

// On the business's own address: PayFast's hand-off page (signed link) and provider notifications.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->name('payments.public.')->group(function (): void {
    Route::get('/pay/{reference}', [PublicPaymentsController::class, 'pay'])->middleware(['signed', 'throttle:60,1'])->name('pay');
    Route::post('/payments/notify/{provider}', [PublicPaymentsController::class, 'notify'])->middleware('throttle:120,1')->name('notify');
});

Route::tenant(function (): void {
    Route::middleware(['permission:payments.manage'])->prefix('settings/payments')->name('tenant.payments.')->group(function (): void {
        Route::get('/', [PaymentSettingsController::class, 'show'])->name('settings');
        Route::put('/payfast', [PaymentSettingsController::class, 'connectPayFast'])->name('payfast');
        Route::put('/yoco', [PaymentSettingsController::class, 'connectYoco'])->middleware('throttle:10,1')->name('yoco');
        Route::delete('/{provider}', [PaymentSettingsController::class, 'disconnect'])->whereIn('provider', ['payfast', 'yoco'])->name('disconnect');
    });
});
