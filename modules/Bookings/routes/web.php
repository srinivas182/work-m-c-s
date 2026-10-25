<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Bookings\Http\Controllers\BookingSettingsController;

Route::tenant(function (): void {
    Route::middleware(['entitled:bookings', 'permission:bookings.manage'])->prefix('bookings')->name('tenant.bookings.')->group(function (): void {
        Route::get('/settings', [BookingSettingsController::class, 'show'])->name('settings');
        Route::post('/services', [BookingSettingsController::class, 'saveService'])->name('services.store');
        Route::put('/services/{service}', [BookingSettingsController::class, 'saveService'])->name('services.update');
        Route::put('/hours/{member}', [BookingSettingsController::class, 'saveHours'])->name('hours.update');
        Route::post('/time-off', [BookingSettingsController::class, 'addTimeOff'])->name('time-off.store');
        Route::delete('/time-off/{timeOff}', [BookingSettingsController::class, 'removeTimeOff'])->name('time-off.destroy');
    });
});
