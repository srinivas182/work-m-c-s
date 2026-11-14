<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Support\Http\Controllers\Admin\InboxController;
use Wurk\Support\Http\Controllers\TicketsController;

// "Contact support" in every business's back office (16.3).
Route::tenant(function (): void {
    Route::prefix('support')->name('tenant.support')->group(function (): void {
        Route::get('/', [TicketsController::class, 'index']);
        Route::get('/new', [TicketsController::class, 'create'])->name('.create');
        Route::post('/suggest', [TicketsController::class, 'suggest'])->middleware('throttle:10,1,support-suggest')->name('.suggest');
        Route::post('/', [TicketsController::class, 'store'])->middleware('throttle:10,60,support-open')->name('.store');
        Route::get('/{number}', [TicketsController::class, 'show'])->whereNumber('number')->name('.show');
        Route::post('/{number}/reply', [TicketsController::class, 'reply'])->whereNumber('number')->middleware('throttle:30,10,support-reply')->name('.reply');
        Route::post('/{number}/rate', [TicketsController::class, 'rate'])->whereNumber('number')->name('.rate');
        Route::get('/{number}/files/{file}', [TicketsController::class, 'file'])->whereNumber('number')->name('.file');
    });
});

// WURK's support inbox.
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff', 'permission:support.manage'])->prefix('support')->name('admin.support')->group(function (): void {
    Route::get('/', [InboxController::class, 'index']);
    Route::get('/{number}', [InboxController::class, 'show'])->whereNumber('number')->name('.show');
    Route::post('/{number}/reply', [InboxController::class, 'reply'])->whereNumber('number')->name('.reply');
    Route::post('/{number}/status', [InboxController::class, 'status'])->whereNumber('number')->name('.status');
    Route::get('/{number}/files/{file}', [InboxController::class, 'file'])->whereNumber('number')->name('.file');
});
