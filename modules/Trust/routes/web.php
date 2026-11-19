<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Trust\Http\Controllers\Admin\VerificationController;
use Wurk\Trust\Http\Controllers\BadgePageController;
use Wurk\Trust\Http\Controllers\VerifiedSettingsController;

// Settings → Verified business (25.4).
Route::tenant(function (): void {
    Route::middleware(['permission:trust.manage'])->prefix('settings/verified')->name('tenant.verified')->group(function (): void {
        Route::get('/', [VerifiedSettingsController::class, 'index']);
        Route::post('/identity', [VerifiedSettingsController::class, 'identity'])->middleware('throttle:6,60,trust-identity')->name('.identity');
        Route::post('/registry', [VerifiedSettingsController::class, 'registry'])->middleware('throttle:6,60,trust-registry')->name('.registry');
    });
});

// Admin → Verification (25.4).
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->prefix('verification')->name('admin.verification')->group(function (): void {
    Route::get('/', [VerificationController::class, 'index']);
    Route::get('/{tenant}', [VerificationController::class, 'show'])->whereUlid('tenant')->name('.show');
    Route::get('/{tenant}/documents/{kind}', [VerificationController::class, 'document'])->whereUlid('tenant')->whereIn('kind', ['id', 'selfie'])->name('.document');
    Route::post('/{tenant}/{check}', [VerificationController::class, 'decide'])->whereUlid('tenant')->whereIn('check', ['identity', 'registry'])->name('.decide');
});

// What the badge means, on WURK's own site (25.4).
Route::domain((string) config('wurk.domains.root'))->middleware('throttle:60,1')
    ->get('/verified/{handle}', BadgePageController::class)->where('handle', '[a-z0-9-]{2,63}')->name('trust.badge');
