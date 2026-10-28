<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Payments\Http\Controllers\PublicPaymentsController;

// On the business's own address: PayFast's hand-off page (signed link) and provider notifications.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->name('payments.public.')->group(function (): void {
    Route::get('/pay/{reference}', [PublicPaymentsController::class, 'pay'])->middleware(['signed', 'throttle:60,1'])->name('pay');
    Route::post('/payments/notify/{provider}', [PublicPaymentsController::class, 'notify'])->middleware('throttle:120,1')->name('notify');
});
