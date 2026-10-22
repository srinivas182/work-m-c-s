<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Billing\Http\Controllers\Admin\BillingAdminController;
use Wurk\Billing\Http\Controllers\Admin\CashPaymentsController;
use Wurk\Billing\Http\Controllers\Admin\CreditPacksController;
use Wurk\Billing\Http\Controllers\BillingController;
use Wurk\Billing\Http\Controllers\DebitOrderController;
use Wurk\Billing\Http\Controllers\InvoiceDownloadController;
use Wurk\Billing\Http\Controllers\PackageChangeController;
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
        Route::get('/change/quote', [PackageChangeController::class, 'quote'])->name('.change.quote');
        Route::post('/change', [PackageChangeController::class, 'store'])->middleware('throttle:10,1,billing-change')->name('.change');
        Route::post('/change/cancel', [PackageChangeController::class, 'cancelScheduled'])->name('.change.cancel');
        Route::get('/invoices/{invoice}', [InvoiceDownloadController::class, 'invoice'])->name('.invoice');
        Route::get('/credit-notes/{note}', [InvoiceDownloadController::class, 'creditNote'])->name('.credit-note');
    });
});

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->group(function (): void {
    Route::get('/credits/packs', [CreditPacksController::class, 'index'])->name('admin.credits.packs');
    Route::post('/credits/packs', [CreditPacksController::class, 'store'])->name('admin.credits.packs.store');
    Route::post('/credits/packs/{pack}/toggle', [CreditPacksController::class, 'toggle'])->name('admin.credits.packs.toggle');
    Route::get('/billing/settings', [BillingAdminController::class, 'settings'])->name('admin.billing.settings');
    Route::put('/billing/settings', [BillingAdminController::class, 'saveSettings'])->name('admin.billing.settings.save');
    Route::get('/billing/failed', [BillingAdminController::class, 'queue'])->name('admin.billing.failed');
    Route::get('/businesses/{tenant}/billing', [BillingAdminController::class, 'business'])->name('admin.businesses.billing');
    Route::post('/businesses/{tenant}/billing/retry', [BillingAdminController::class, 'retry'])->middleware('throttle:10,1,admin-billing-retry')->name('admin.businesses.billing.retry');
    Route::get('/businesses/{tenant}/invoices/{invoice}', [BillingAdminController::class, 'download'])->name('admin.businesses.invoice');
    Route::post('/businesses/{tenant}/invoices/{invoice}/credit', [BillingAdminController::class, 'credit'])->name('admin.businesses.invoice.credit');
    Route::post('/businesses/{tenant}/credit-notes/{note}/refunded', [BillingAdminController::class, 'markRefunded'])->name('admin.businesses.credit-note.refunded');
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
