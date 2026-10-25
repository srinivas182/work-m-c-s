<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Bookings\Http\Controllers\BookingSettingsController;
use Wurk\Bookings\Http\Controllers\CalendarController;
use Wurk\Bookings\Http\Controllers\PublicBookingController;
use Wurk\Bookings\Http\Controllers\PublicManageController;
use Wurk\Bookings\Http\Controllers\PublicTableController;
use Wurk\Bookings\Http\Controllers\TablesController;

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
        Route::put('/payouts', [BookingSettingsController::class, 'connectPayouts'])->name('payouts.connect');
        Route::delete('/payouts', [BookingSettingsController::class, 'disconnectPayouts'])->name('payouts.disconnect');
        Route::put('/policy', [BookingSettingsController::class, 'savePolicy'])->name('policy.save');
        Route::put('/messages', [BookingSettingsController::class, 'saveMessages'])->name('messages.save');
        Route::get('/tables', [TablesController::class, 'show'])->name('tables');
        Route::post('/tables', [TablesController::class, 'store'])->name('tables.store');
        Route::post('/tables/{reservation}/status', [TablesController::class, 'status'])->name('tables.status');
        Route::put('/tables/settings', [TablesController::class, 'saveSettings'])->name('tables.settings');
    });
});

// Online booking on the business's own website ({handle}.{root}): no session or CSRF (nothing signed in),
// a hidden spam field and rate limits — as on the quote form.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->prefix('book')->name('bookings.public.')->group(function (): void {
    Route::get('/services', [PublicBookingController::class, 'services'])->middleware('throttle:60,1,book-read')->name('services');
    Route::get('/times', [PublicBookingController::class, 'times'])->middleware('throttle:60,1,book-read')->name('times');
    Route::post('/', [PublicBookingController::class, 'book'])->middleware('throttle:5,10,book-create')->name('book');
    Route::get('/tables/times', [PublicTableController::class, 'times'])->middleware('throttle:60,1,book-read')->name('tables.times');
    Route::post('/tables', [PublicTableController::class, 'reserve'])->middleware('throttle:5,10,book-create')->name('tables.reserve');
    Route::middleware(['signed', 'throttle:30,1,book-manage'])->group(function (): void {
        Route::get('/tables/{reservation}', [PublicTableController::class, 'show'])->name('table');
        Route::post('/tables/{reservation}/cancel', [PublicTableController::class, 'cancel'])->name('table.cancel');
    });
    Route::get('/return', [PublicBookingController::class, 'paid'])->middleware('throttle:30,1,book-read')->name('paid');
    // The customer's own booking page: signed links (no account), expiring when the booking starts.
    // The page checks its own signature (ignoring only the chosen ?date); each action has its own signed address.
    Route::get('/manage/{booking}', [PublicManageController::class, 'show'])->middleware('throttle:30,1,book-manage')->name('manage');
    Route::middleware(['signed', 'throttle:30,1,book-manage'])->group(function (): void {
        Route::post('/manage/{booking}/cancel', [PublicManageController::class, 'cancel'])->name('manage.cancel');
        Route::post('/manage/{booking}/move', [PublicManageController::class, 'move'])->name('manage.move');
    });
});
