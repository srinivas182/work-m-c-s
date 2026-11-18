<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Money\Http\Controllers\OwedController;
use Wurk\Money\Http\Controllers\PublicInvoiceController;

Route::tenant(function (): void {
    Route::middleware(['permission:money.manage'])->prefix('money')->name('tenant.money.')->group(function (): void {
        // Who owes me (23.5a).
        Route::get('/owed', [OwedController::class, 'index'])->name('owed');
        Route::post('/invoices', [OwedController::class, 'store'])->middleware('throttle:30,1,money-invoices')->name('invoices.store');
        Route::post('/invoices/{invoice}/send', [OwedController::class, 'send'])->middleware('throttle:30,1,money-send')->name('invoices.send');
        Route::post('/invoices/{invoice}/remind', [OwedController::class, 'remind'])->middleware('throttle:30,1,money-send')->name('invoices.remind');
        Route::post('/invoices/{invoice}/paid', [OwedController::class, 'paid'])->name('invoices.paid');
        Route::put('/invoices/{invoice}/reminders', [OwedController::class, 'reminders'])->name('invoices.reminders');
        Route::post('/invoices/{invoice}/void', [OwedController::class, 'void'])->name('invoices.void');
    });
});

// The customer's invoice on the business's own address: signed links only.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->middleware(['signed', 'throttle:60,1,money-public'])->prefix('invoice')->name('money.public.')->group(function (): void {
    Route::get('/{invoice}', [PublicInvoiceController::class, 'show'])->whereUlid('invoice')->name('invoice');
    Route::post('/{invoice}/pay', [PublicInvoiceController::class, 'pay'])->whereUlid('invoice')->name('pay');
});
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->middleware('throttle:60,1,money-public')->prefix('invoice')->name('money.public.')->group(function (): void {
    Route::get('/{invoice}/paid', [PublicInvoiceController::class, 'returned'])->whereUlid('invoice')->name('returned'); // signed, checked in the controller (the provider adds its reference)
});
