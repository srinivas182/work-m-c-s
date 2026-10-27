<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Bookings\Http\Controllers\BookingSettingsController;
use Wurk\Bookings\Http\Controllers\CalendarController;
use Wurk\Bookings\Http\Controllers\ClassesController;
use Wurk\Bookings\Http\Controllers\PublicBookingController;
use Wurk\Bookings\Http\Controllers\PublicClassController;
use Wurk\Bookings\Http\Controllers\PublicManageController;
use Wurk\Bookings\Http\Controllers\PublicTableController;
use Wurk\Bookings\Http\Controllers\TablesController;
use Wurk\Bookings\Http\Controllers\VideoConnectionController;

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
        Route::get('/video/connect/{provider}', [VideoConnectionController::class, 'connect'])->whereIn('provider', ['google', 'zoom'])->name('video.connect');
        Route::put('/video/fixed', [VideoConnectionController::class, 'fixed'])->name('video.fixed');
        Route::put('/video/links', [BookingSettingsController::class, 'saveLinks'])->name('video.links');
        Route::delete('/video', [VideoConnectionController::class, 'disconnect'])->name('video.disconnect');
        Route::get('/classes', [ClassesController::class, 'show'])->name('classes');
        Route::post('/classes', [ClassesController::class, 'store'])->name('classes.store');
        Route::post('/classes/{session}/attendees', [ClassesController::class, 'addAttendee'])->name('classes.attendees');
        Route::post('/classes/attendees/{attendee}/status', [ClassesController::class, 'attendance'])->name('classes.attendance');
        Route::post('/classes/{session}/cancel', [ClassesController::class, 'cancel'])->name('classes.cancel');
        Route::get('/tables', [TablesController::class, 'show'])->name('tables');
        Route::post('/tables', [TablesController::class, 'store'])->name('tables.store');
        Route::post('/tables/{reservation}/status', [TablesController::class, 'status'])->name('tables.status');
        Route::put('/tables/settings', [TablesController::class, 'saveSettings'])->name('tables.settings');
    });
});

// Online booking on the business's own website ({handle}.{root}): no session or CSRF (nothing signed in),
// a hidden spam field and rate limits — as on the quote form.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->prefix('book')->name('bookings.public.')->group(function (): void {
    Route::get('/services', [PublicBookingController::class, 'services'])->middleware('throttle:book-read')->name('services');
    Route::get('/times', [PublicBookingController::class, 'times'])->middleware('throttle:book-read')->name('times');
    Route::post('/', [PublicBookingController::class, 'book'])->middleware('throttle:book-create')->name('book');
    Route::get('/tables/times', [PublicTableController::class, 'times'])->middleware('throttle:book-read')->name('tables.times');
    Route::post('/tables', [PublicTableController::class, 'reserve'])->middleware('throttle:book-create')->name('tables.reserve');
    Route::middleware(['signed', 'throttle:book-manage'])->group(function (): void {
        Route::get('/tables/{reservation}', [PublicTableController::class, 'show'])->name('table');
        Route::post('/tables/{reservation}/cancel', [PublicTableController::class, 'cancel'])->name('table.cancel');
    });
    Route::get('/classes', [PublicClassController::class, 'index'])->middleware('throttle:book-read')->name('classes');
    Route::post('/classes/{session}', [PublicClassController::class, 'attend'])->middleware('throttle:book-create')->name('classes.attend');
    Route::middleware(['signed', 'throttle:book-manage'])->group(function (): void {
        Route::get('/class/{attendee}', [PublicClassController::class, 'show'])->name('class');
        Route::post('/class/{attendee}/cancel', [PublicClassController::class, 'leave'])->name('class.leave');
    });
    Route::get('/return', [PublicBookingController::class, 'paid'])->middleware('throttle:book-read')->name('paid');
    // The customer's own booking page: signed links (no account), expiring when the booking starts.
    // The page checks its own signature (ignoring only the chosen ?date); each action has its own signed address.
    Route::get('/manage/{booking}', [PublicManageController::class, 'show'])->middleware('throttle:book-manage')->name('manage');
    Route::middleware(['signed', 'throttle:book-manage'])->group(function (): void {
        Route::post('/manage/{booking}/cancel', [PublicManageController::class, 'cancel'])->name('manage.cancel');
        Route::post('/manage/{booking}/move', [PublicManageController::class, 'move'])->name('manage.move');
    });
});

// Google and Zoom send owners back here after connecting (one fixed address, registered with each provider).
Route::domain((string) config('wurk.domains.app'))->middleware(['web', 'auth'])
    ->get('/oauth/video/{provider}/callback', [VideoConnectionController::class, 'callback'])->whereIn('provider', ['google', 'zoom'])->name('bookings.video.callback');
