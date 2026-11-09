<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Reports\Http\Controllers\ReportsController;
use Wurk\Reports\Http\Controllers\VatController;

Route::tenant(function (): void {
    Route::get('/reports', ReportsController::class)->name('tenant.reports');
    Route::middleware('permission:billing.manage')->group(function (): void {
        Route::get('/settings/vat', [VatController::class, 'show'])->name('tenant.vat');
        Route::put('/settings/vat', [VatController::class, 'update'])->name('tenant.vat.update');
    });
});
