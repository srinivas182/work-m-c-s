<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Admin\Http\Controllers\DashboardController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('admin.home');
    });
