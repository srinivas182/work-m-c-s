<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Referrals\Http\Controllers\PayoutsController;
use Wurk\Referrals\Http\Controllers\ReferralLinkController;
use Wurk\Referrals\Http\Controllers\ReferralMembersController;
use Wurk\Referrals\Http\Controllers\ReferralSettingsController;
use Wurk\Referrals\Http\Controllers\ReportsController;

Route::tenant(function (): void {
    Route::get('/referral-reports', ReportsController::class)->middleware(['entitled:referrals', 'permission:referrals.manage'])->name('tenant.referral-reports');
    Route::middleware(['entitled:referrals', 'permission:referrals.manage'])->prefix('payouts')->name('tenant.payouts')->group(function (): void {
        Route::get('/', [PayoutsController::class, 'index']);
        Route::post('/{payout}/paid', [PayoutsController::class, 'paid'])->name('.paid');
        Route::post('/{payout}/decline', [PayoutsController::class, 'decline'])->name('.decline');
    });
    Route::middleware(['entitled:referrals', 'permission:referrals.manage'])->prefix('referrals')->name('tenant.referrals')->group(function (): void {
        Route::get('/', [ReferralMembersController::class, 'index']);
        Route::get('/{member}/qr.svg', [ReferralMembersController::class, 'qr'])->name('.qr');
    });
    Route::middleware(['entitled:referrals', 'permission:referrals.manage'])->prefix('settings/referrals')->name('tenant.referrals.settings')->group(function (): void {
        Route::get('/', [ReferralSettingsController::class, 'show']);
        Route::put('/', [ReferralSettingsController::class, 'update'])->name('.update');
        Route::put('/wallet', [ReferralSettingsController::class, 'wallet'])->name('.wallet');
    });
});

// Referral links on the business's own site: {handle}.{root}/r/CODE (no session; remembers the code in a cookie).
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])
    ->get('/r/{code}', ReferralLinkController::class)->where('code', '[A-Za-z0-9]{7}')->middleware('throttle:60,1,referral-links')->name('referrals.link');
