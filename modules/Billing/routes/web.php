<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Billing\Http\Controllers\Admin\CashPaymentsController;
use Wurk\Billing\Http\Controllers\Admin\CreditPacksController;
use Wurk\Billing\Http\Controllers\BillingController;
use Wurk\Billing\Http\Controllers\DebitOrderController;
use Wurk\Billing\Http\Controllers\PaystackWebhookController;

Route::tenant(function (): void {
    Route::middleware('permission:billing.manage')->prefix('settings/billing')->name('tenant.billing')->group(function (): void {
        Route::get('/', [BillingController::class, 'show']);
        Route::put('/account', [BillingController::class, 'saveAccount'])->name('.account');
        Route::post('/pay', [BillingController::class, 'pay'])->middleware('throttle:10,1,.pay')->name('.pay');
        Route::post('/credits', [BillingController::class, 'buyCredits'])->middleware('throttle:10,1,.credits')->name('.credits');
        Route::post('/card', [BillingController::class, 'updateCard'])->middleware('throttle:10,1,.card')->name('.card');
        Route::get('/return', [BillingController::class, 'returned'])->name('.return');
        Route::post('/debit-order', [DebitOrderController::class, 'store'])->middleware('throttle:5,10,.debit-order')->name('.debit-order');
        Route::post('/debit-order/use-card', [DebitOrderController::class, 'useCard'])->name('.use-card');
        Route::post('/debit-order/simulate', [DebitOrderController::class, 'simulate'])->name('.simulate');
    });
});

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->group(function (): void {
    Route::get('/credits/packs', [CreditPacksController::class, 'index'])->name('admin.credits.packs');
    Route::post('/credits/packs', [CreditPacksController::class, 'store'])->name('admin.credits.packs.store');
    Route::post('/credits/packs/{pack}/toggle', [CreditPacksController::class, 'toggle'])->name('admin.credits.packs.toggle');
    Route::get('/businesses/{tenant}/cash', [CashPaymentsController::class, 'show'])->name('admin.businesses.cash');
    Route::post('/businesses/{tenant}/cash', [CashPaymentsController::class, 'store'])->name('admin.businesses.cash.store');
    Route::post('/businesses/{tenant}/cash/{cash}/approve', [CashPaymentsController::class, 'approve'])->middleware('throttle:cash-approve')->name('admin.businesses.cash.approve');
    Route::post('/businesses/{tenant}/cash/{cash}/resend', [CashPaymentsController::class, 'resend'])->middleware('throttle:cash-resend')->name('admin.businesses.cash.resend');
    Route::post('/businesses/{tenant}/cash/{cash}/cancel', [CashPaymentsController::class, 'cancel'])->name('admin.businesses.cash.cancel');
});

Route::domain((string) config('wurk.domains.app'))->withoutMiddleware(['web'])->middleware('throttle:600,1')
    ->group(function (): void {
        Route::post('/webhooks/billing/paystack', PaystackWebhookController::class)->name('webhooks.paystack');
        Route::post('/webhooks/billing/debit-orders', [DebitOrderController::class, 'webhook'])->name('webhooks.debit-orders');
    });
