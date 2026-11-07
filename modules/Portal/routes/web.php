<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Portal\Http\Controllers\DataRequestsController;
use Wurk\Portal\Http\Controllers\PortalController;

// The customer portal on a business's own address: {handle}.{root}/account (its own host-only session).
Route::domain('{handle}.'.config('wurk.domains.root'))->middleware('web')->prefix('account')->name('portal')->group(function (): void {
    Route::get('/', [PortalController::class, 'show']);
    Route::post('/code', [PortalController::class, 'code'])->middleware('throttle:10,1,portal-code')->name('.code');
    Route::post('/verify', [PortalController::class, 'verify'])->middleware('throttle:10,1,portal-verify')->name('.verify');
    Route::post('/consent', [PortalController::class, 'consent'])->name('.consent');
    Route::post('/sign-out', [PortalController::class, 'signOut'])->name('.sign-out');
    Route::get('/referral-qr.svg', [PortalController::class, 'qr'])->name('.qr');
    Route::get('/id-qr.svg', [PortalController::class, 'idQr'])->middleware('throttle:30,1,id-qr')->name('.id-qr');
    Route::get('/my-information.json', [PortalController::class, 'myData'])->middleware('throttle:10,1,my-data')->name('.my-data');
    Route::post('/delete-request', [PortalController::class, 'requestDeletion'])->middleware('throttle:5,1,delete-request')->name('.delete-request');
    Route::post('/payout', [PortalController::class, 'requestPayout'])->middleware('throttle:5,1,payout')->name('.payout');
    Route::get('/wallet-pass', [PortalController::class, 'walletPass'])->middleware('throttle:30,1,wallet-pass')->name('.wallet-pass');
});

// The business: Customers → Data requests (POPIA).
Route::tenant(function (): void {
    Route::middleware('permission:contacts.manage')->prefix('data-requests')->name('tenant.data-requests')->group(function (): void {
        Route::get('/', [DataRequestsController::class, 'index']);
        Route::post('/{id}/settle', [DataRequestsController::class, 'settle'])->name('.settle');
        Route::post('/{id}/approve', [DataRequestsController::class, 'approve'])->name('.approve');
        Route::post('/{id}/decline', [DataRequestsController::class, 'decline'])->name('.decline');
    });
});
