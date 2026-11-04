<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Referrals\Http\Controllers\ReferralSettingsController;

Route::tenant(function (): void {
    Route::middleware(['entitled:referrals', 'permission:referrals.manage'])->prefix('settings/referrals')->name('tenant.referrals.settings')->group(function (): void {
        Route::get('/', [ReferralSettingsController::class, 'show']);
        Route::put('/', [ReferralSettingsController::class, 'update'])->name('.update');
    });
});
