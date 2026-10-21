<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Security\Http\Controllers\SecurityController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/security', [SecurityController::class, 'platform'])->name('admin.security');
        Route::put('/security', [SecurityController::class, 'updatePlatform'])->name('admin.security.update');
    });

Route::tenant(function (): void {
    Route::middleware('permission:team-security.manage')->group(function (): void {
        Route::get('/settings/security', [SecurityController::class, 'business'])->name('tenant.security');
        Route::put('/settings/security', [SecurityController::class, 'updateBusiness'])->name('tenant.security.update');
    });
});
