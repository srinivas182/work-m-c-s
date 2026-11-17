<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Watchtower\Http\Controllers\IncidentsController;
use Wurk\Watchtower\Http\Controllers\SystemController;

// Admin → Watchtower (18.0).
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff', 'permission:watchtower.view'])->prefix('watchtower')->name('admin.watchtower')->group(function (): void {
    Route::get('/', [IncidentsController::class, 'index']);
    Route::get('/{incident}', [IncidentsController::class, 'show'])->whereUlid('incident')->name('.show');
    Route::middleware('permission:watchtower.manage')->group(function (): void {
        Route::put('/settings', [IncidentsController::class, 'settings'])->name('.settings');
        Route::post('/uptime-token', [IncidentsController::class, 'uptimeToken'])->name('.uptime-token'); // 19.6
        Route::put('/{incident}', [IncidentsController::class, 'update'])->whereUlid('incident')->name('.update');
        Route::post('/{incident}/analyse', [IncidentsController::class, 'analyse'])->whereUlid('incident')->middleware('throttle:10,1,watchtower-analyse')->name('.analyse');
    });
});

// Admin → System (18.4c): migrations, backups, restore drills, business moves.
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff', 'permission:watchtower.view'])
    ->get('system', SystemController::class)->name('admin.system');
