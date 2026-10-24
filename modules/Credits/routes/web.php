<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Credits\Http\Controllers\CreditsController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/credits/rates', [CreditsController::class, 'rates'])->name('admin.credits.rates');
        Route::post('/credits/rates', [CreditsController::class, 'setRate'])->name('admin.credits.rates.store');
        Route::get('/businesses/{tenant}/credits', [CreditsController::class, 'business'])->name('admin.businesses.credits');
        Route::post('/businesses/{tenant}/credits', [CreditsController::class, 'adjust'])->name('admin.businesses.credits.adjust');
    });

Route::tenant(function (): void {
    Route::middleware('permission:credits.view-own')->group(function (): void {
        Route::get('/settings/credits', [CreditsController::class, 'mine'])->name('tenant.credits');
        Route::put('/settings/credits/alert', [CreditsController::class, 'threshold'])->name('tenant.credits.alert');
        Route::get('/settings/credits/statement.csv', [CreditsController::class, 'statement'])->name('tenant.credits.statement');
        Route::put('/settings/credits/limits', [CreditsController::class, 'limits'])->name('tenant.credits.limits');
    });
});
