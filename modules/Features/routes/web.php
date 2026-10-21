<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Features\Http\Controllers\FeaturesController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/features', [FeaturesController::class, 'admin'])->name('admin.features');
        Route::put('/features/{feature}', [FeaturesController::class, 'update'])->name('admin.features.update');
    });

Route::tenant(function (): void {
    Route::middleware('permission:features.opt-in')->group(function (): void {
        Route::get('/settings/features', [FeaturesController::class, 'business'])->name('tenant.features');
        Route::put('/settings/features/{feature}', [FeaturesController::class, 'optIn'])->name('tenant.features.opt-in');
    });
});
