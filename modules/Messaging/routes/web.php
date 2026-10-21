<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Messaging\Http\Controllers\ProvidersController;
use Wurk\Messaging\Http\Webhooks\DeliveryWebhooks;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/providers', [ProvidersController::class, 'index'])->name('admin.providers');
        Route::put('/providers/{channel}', [ProvidersController::class, 'update'])->name('admin.providers.update');
        Route::post('/providers/{channel}/test', [ProvidersController::class, 'test'])->middleware('throttle:10,1')->name('admin.providers.test');
    });

// Delivery receipts from providers (signature-verified; no session or CSRF).
Route::domain((string) config('wurk.domains.app'))
    ->withoutMiddleware(['web'])
    ->middleware('throttle:600,1')
    ->prefix('webhooks/messaging')
    ->group(function (): void {
        Route::post('/twilio', [DeliveryWebhooks::class, 'twilio'])->name('webhooks.twilio');
        Route::get('/meta', [DeliveryWebhooks::class, 'metaVerify'])->name('webhooks.meta.verify');
        Route::post('/meta', [DeliveryWebhooks::class, 'meta'])->name('webhooks.meta');
    });
