<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Portal\Http\Controllers\PortalController;

// The customer portal on a business's own address: {handle}.{root}/account (its own host-only session).
Route::domain('{handle}.'.config('wurk.domains.root'))->middleware('web')->prefix('account')->name('portal')->group(function (): void {
    Route::get('/', [PortalController::class, 'show']);
    Route::post('/code', [PortalController::class, 'code'])->middleware('throttle:10,1,portal-code')->name('.code');
    Route::post('/verify', [PortalController::class, 'verify'])->middleware('throttle:10,1,portal-verify')->name('.verify');
    Route::post('/consent', [PortalController::class, 'consent'])->name('.consent');
    Route::post('/sign-out', [PortalController::class, 'signOut'])->name('.sign-out');
});
