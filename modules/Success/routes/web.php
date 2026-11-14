<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Success\Http\Controllers\HealthController;

// Admin → Business health (16.5).
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff', 'permission:health.view'])->prefix('health')->name('admin.health')->group(function (): void {
    Route::get('/', [HealthController::class, 'index']);
    Route::get('/export', [HealthController::class, 'export'])->middleware('throttle:10,1,health-export')->name('.export');
    Route::put('/settings', [HealthController::class, 'settings'])->name('.settings');
});
