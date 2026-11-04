<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Referrals\Http\Controllers\ReferralLinkController;
use Wurk\Referrals\Http\Controllers\ReferralMembersController;
use Wurk\Referrals\Http\Controllers\ReferralSettingsController;

Route::tenant(function (): void {
    Route::middleware(['entitled:referrals', 'permission:referrals.manage'])->prefix('referrals')->name('tenant.referrals')->group(function (): void {
        Route::get('/', [ReferralMembersController::class, 'index']);
        Route::get('/{member}/qr.svg', [ReferralMembersController::class, 'qr'])->name('.qr');
    });
    Route::middleware(['entitled:referrals', 'permission:referrals.manage'])->prefix('settings/referrals')->name('tenant.referrals.settings')->group(function (): void {
        Route::get('/', [ReferralSettingsController::class, 'show']);
        Route::put('/', [ReferralSettingsController::class, 'update'])->name('.update');
    });
});

// Referral links on the business's own site: {handle}.{root}/r/CODE (no session; remembers the code in a cookie).
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])
    ->get('/r/{code}', ReferralLinkController::class)->where('code', '[A-Za-z0-9]{7}')->middleware('throttle:60,1,referral-links')->name('referrals.link');
