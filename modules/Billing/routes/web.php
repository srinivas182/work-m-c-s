<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Billing\Http\Controllers\Admin\CreditPacksController;
use Wurk\Billing\Http\Controllers\BillingController;
use Wurk\Billing\Http\Controllers\PaystackWebhookController;

Route::tenant(function (): void {
    Route::middleware('permission:billing.manage')->prefix('settings/billing')->name('tenant.billing')->group(function (): void {
        Route::get('/', [BillingController::class, 'show']);
        Route::put('/account', [BillingController::class, 'saveAccount'])->name('.account');
        Route::post('/pay', [BillingController::class, 'pay'])->middleware('throttle:10,1')->name('.pay');
        Route::post('/credits', [BillingController::class, 'buyCredits'])->middleware('throttle:10,1')->name('.credits');
        Route::post('/card', [BillingController::class, 'updateCard'])->middleware('throttle:10,1')->name('.card');
        Route::get('/return', [BillingController::class, 'returned'])->name('.return');
    });
});

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->group(function (): void {
    Route::get('/credits/packs', [CreditPacksController::class, 'index'])->name('admin.credits.packs');
    Route::post('/credits/packs', [CreditPacksController::class, 'store'])->name('admin.credits.packs.store');
    Route::post('/credits/packs/{pack}/toggle', [CreditPacksController::class, 'toggle'])->name('admin.credits.packs.toggle');
});

Route::domain((string) config('wurk.domains.app'))->withoutMiddleware(['web'])->middleware('throttle:600,1')
    ->post('/webhooks/billing/paystack', PaystackWebhookController::class)->name('webhooks.paystack');
