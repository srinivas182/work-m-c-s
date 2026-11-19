<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Shifts\Http\Controllers\MyShiftsController;
use Wurk\Shifts\Http\Controllers\RosterController;

Route::tenant(function (): void {
    // My shifts (26.4): everyone's own.
    Route::prefix('shifts/mine')->name('tenant.shifts.mine')->middleware('throttle:60,1,shifts-mine')->group(function (): void {
        Route::get('/', [MyShiftsController::class, 'show']);
        Route::post('/leave', [MyShiftsController::class, 'leave'])->name('.leave');
        Route::post('/swap', [MyShiftsController::class, 'swap'])->name('.swap');
        Route::post('/clock-in', [MyShiftsController::class, 'clockIn'])->name('.in');
        Route::post('/clock-out', [MyShiftsController::class, 'clockOut'])->name('.out');
    });
    // Team → Shifts (26.4): the owner's.
    Route::middleware('permission:shifts.manage')->prefix('shifts')->name('tenant.shifts.')->group(function (): void {
        Route::get('/', [RosterController::class, 'show'])->name('roster');
        Route::post('/fill', [RosterController::class, 'fill'])->name('fill');
        Route::post('/', [RosterController::class, 'store'])->name('store');
        Route::delete('/{shift}', [RosterController::class, 'destroy'])->name('destroy');
        Route::post('/requests/{shiftRequest}', [RosterController::class, 'decide'])->name('decide');
        Route::put('/clock', [RosterController::class, 'clockSettings'])->name('clock');
        Route::get('/earnings', [RosterController::class, 'earnings'])->name('earnings');
        Route::get('/earnings/export', [RosterController::class, 'export'])->name('export');
        Route::put('/rates/{member}', [RosterController::class, 'rate'])->name('rate');
        Route::post('/tips', [RosterController::class, 'tip'])->name('tip');
    });
});
