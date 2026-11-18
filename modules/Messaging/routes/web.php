<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Messaging\Http\Controllers\MessagesController;
use Wurk\Messaging\Http\Controllers\ProvidersController;
use Wurk\Messaging\Http\Controllers\WhatsAppConnectionController;
use Wurk\Messaging\Http\Webhooks\DeliveryWebhooks;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/providers', [ProvidersController::class, 'index'])->name('admin.providers');
        Route::put('/providers/{channel}', [ProvidersController::class, 'update'])->name('admin.providers.update');
        Route::post('/providers/{channel}/test', [ProvidersController::class, 'test'])->middleware('throttle:10,1,admin.providers.test')->name('admin.providers.test');
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

// Meta sends owners back here after Embedded Signup (one fixed address, registered with Meta; Sprint 21.1).
Route::domain((string) config('wurk.domains.app'))->middleware(['web', 'auth'])
    ->get('/whatsapp/connected', [WhatsAppConnectionController::class, 'callback'])->name('whatsapp.connected');

// Settings → Messages: the business's customer messages (owners: messages.manage).
Route::tenant(function (): void {
    Route::middleware(['permission:messages.manage'])->prefix('settings/messages')->name('tenant.messages')->group(function (): void {
        Route::get('/', [MessagesController::class, 'index']);
        Route::put('/{key}/{channel}', [MessagesController::class, 'save'])->name('.save');
        Route::delete('/{key}/{channel}', [MessagesController::class, 'reset'])->name('.reset');
        Route::put('/{key}', [MessagesController::class, 'enabled'])->name('.enabled');
        Route::post('/{key}/{channel}/test', [MessagesController::class, 'test'])->middleware('throttle:10,1,message-tests')->name('.test');
    });
    // Settings → WhatsApp: the business's own number (Sprint 21.1).
    Route::middleware(['permission:messages.manage'])->prefix('settings/whatsapp')->name('tenant.whatsapp')->group(function (): void {
        Route::get('/', [WhatsAppConnectionController::class, 'index']);
        Route::post('/connect', [WhatsAppConnectionController::class, 'connect'])->middleware('throttle:10,1,whatsapp-connect')->name('.connect');
        Route::post('/refresh', [WhatsAppConnectionController::class, 'refresh'])->middleware('throttle:10,1,whatsapp-refresh')->name('.refresh');
        Route::delete('/', [WhatsAppConnectionController::class, 'disconnect'])->name('.disconnect');
    });
});
