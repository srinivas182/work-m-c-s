<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Reports\Http\Controllers\ReportsController;

Route::tenant(function (): void {
    Route::get('/reports', ReportsController::class)->name('tenant.reports');
});
