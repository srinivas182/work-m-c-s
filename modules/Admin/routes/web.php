<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Admin\Http\Controllers\DashboardController;
use Wurk\Admin\Http\Controllers\MetricsController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('admin.home');
        Route::get('/metrics', MetricsController::class)->name('admin.metrics'); // WURK's SaaS metrics (15.4)
    });
