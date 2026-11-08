<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Portal\Http\Controllers\CustomerAccountsController;
use Wurk\Portal\Http\Controllers\DataRequestsController;
use Wurk\Portal\Http\Controllers\MyWurkController;
use Wurk\Portal\Http\Controllers\PortalController;

// The customer portal on a business's own address: {handle}.{root}/account (its own host-only session).
Route::domain('{handle}.'.config('wurk.domains.root'))->middleware('web')->prefix('account')->name('portal')->group(function (): void {
    Route::get('/', [PortalController::class, 'show']);
    Route::post('/code', [PortalController::class, 'code'])->middleware('throttle:10,1,portal-code')->name('.code');
    Route::post('/verify', [PortalController::class, 'verify'])->middleware('throttle:10,1,portal-verify')->name('.verify');
    Route::post('/consent', [PortalController::class, 'consent'])->name('.consent');
    Route::get('/handoff', [PortalController::class, 'handoff'])->middleware('throttle:30,1,handoff')->name('.handoff');
    Route::post('/password', [PortalController::class, 'password'])->middleware('throttle:10,1,portal-password')->name('.password');
    Route::post('/set-password', [PortalController::class, 'setPassword'])->middleware('throttle:10,1,portal-set-password')->name('.set-password');
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
    Route::middleware('permission:contacts.manage')->prefix('settings/customer-accounts')->name('tenant.customer-accounts')->group(function (): void {
        Route::get('/', [CustomerAccountsController::class, 'show']);
        Route::put('/', [CustomerAccountsController::class, 'update'])->name('.update');
        Route::post('/send', [CustomerAccountsController::class, 'send'])->middleware('throttle:20,1,portal-send')->name('.send');
    });
    Route::middleware('permission:contacts.manage')->prefix('data-requests')->name('tenant.data-requests')->group(function (): void {
        Route::get('/', [DataRequestsController::class, 'index']);
        Route::post('/{id}/settle', [DataRequestsController::class, 'settle'])->name('.settle');
        Route::post('/{id}/approve', [DataRequestsController::class, 'approve'])->name('.approve');
        Route::post('/{id}/decline', [DataRequestsController::class, 'decline'])->name('.decline');
    });
});

// My WURK: a customer's businesses in one place (Sprint 13.8).
Route::domain((string) config('wurk.domains.my'))->middleware('web')->name('my')->group(function (): void {
    Route::get('/', [MyWurkController::class, 'show']);
    Route::post('/code', [MyWurkController::class, 'code'])->middleware('throttle:10,1,my-code')->name('.code');
    Route::post('/verify', [MyWurkController::class, 'verify'])->middleware('throttle:10,1,my-verify')->name('.verify');
    Route::post('/password', [MyWurkController::class, 'password'])->middleware('throttle:10,1,my-password')->name('.password');
    Route::middleware('auth')->group(function (): void {
        Route::get('/open/{handle}', [MyWurkController::class, 'open'])->middleware('throttle:30,1,my-open')->name('.open');
        Route::post('/hide/{handle}', [MyWurkController::class, 'hide'])->name('.hide');
        Route::post('/sign-out', [MyWurkController::class, 'signOut'])->name('.sign-out');
    });
});
