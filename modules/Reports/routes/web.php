<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Reports\Http\Controllers\ReportsController;
use Wurk\Reports\Http\Controllers\VatController;

Route::tenant(function (): void {
    Route::get('/reports', ReportsController::class)->name('tenant.reports');
    Route::get('/reports/export/{format}', [ReportsController::class, 'export'])->whereIn('format', ['csv', 'pdf'])->middleware('throttle:20,1,report-export')->name('tenant.reports.export');
    Route::put('/reports/summary', [ReportsController::class, 'summary'])->name('tenant.reports.summary');
    Route::middleware('permission:billing.manage')->group(function (): void {
        Route::get('/settings/vat', [VatController::class, 'show'])->name('tenant.vat');
        Route::put('/settings/vat', [VatController::class, 'update'])->name('tenant.vat.update');
    });
});
