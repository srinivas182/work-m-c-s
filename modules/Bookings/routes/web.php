<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Bookings\Http\Controllers\BookingSettingsController;
use Wurk\Bookings\Http\Controllers\CalendarController;
use Wurk\Bookings\Http\Controllers\PublicBookingController;

Route::tenant(function (): void {
    // The calendar: everyone who can see bookings; changes need bookings.manage.
    Route::middleware(['entitled:bookings', 'permission:bookings.view'])->prefix('bookings')->name('tenant.bookings.')->group(function (): void {
        Route::get('/', [CalendarController::class, 'show'])->name('calendar');
        Route::middleware('permission:bookings.manage')->group(function (): void {
            Route::post('/', [CalendarController::class, 'store'])->name('store');
            Route::put('/{booking}/move', [CalendarController::class, 'move'])->name('move');
            Route::post('/{booking}/status', [CalendarController::class, 'status'])->name('status');
        });
    });
    Route::middleware(['entitled:bookings', 'permission:bookings.manage'])->prefix('bookings')->name('tenant.bookings.')->group(function (): void {
        Route::get('/settings', [BookingSettingsController::class, 'show'])->name('settings');
        Route::post('/services', [BookingSettingsController::class, 'saveService'])->name('services.store');
        Route::put('/services/{service}', [BookingSettingsController::class, 'saveService'])->name('services.update');
        Route::put('/hours/{member}', [BookingSettingsController::class, 'saveHours'])->name('hours.update');
        Route::post('/time-off', [BookingSettingsController::class, 'addTimeOff'])->name('time-off.store');
        Route::delete('/time-off/{timeOff}', [BookingSettingsController::class, 'removeTimeOff'])->name('time-off.destroy');
    });
});

// Online booking on the business's own website ({handle}.{root}): no session or CSRF (nothing signed in),
// a hidden spam field and rate limits — as on the quote form.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->prefix('book')->name('bookings.public.')->group(function (): void {
    Route::get('/services', [PublicBookingController::class, 'services'])->middleware('throttle:60,1,book-read')->name('services');
    Route::get('/times', [PublicBookingController::class, 'times'])->middleware('throttle:60,1,book-read')->name('times');
    Route::post('/', [PublicBookingController::class, 'book'])->middleware('throttle:5,10,book-create')->name('book');
    Route::get('/return', [PublicBookingController::class, 'paid'])->middleware('throttle:30,1,book-read')->name('paid');
});
