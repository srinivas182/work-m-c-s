<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Push\Http\Controllers\NotificationsController;

// Settings → Notifications on my phone (26.2): each person's own.
Route::tenant(function (): void {
    Route::prefix('settings/notifications')->name('tenant.push.')->middleware('throttle:30,1,push-settings')->group(function (): void {
        Route::get('/', [NotificationsController::class, 'show'])->name('settings');
        Route::put('/', [NotificationsController::class, 'update'])->name('update');
        Route::post('/phones', [NotificationsController::class, 'subscribe'])->name('subscribe');
        Route::post('/phones/remove', [NotificationsController::class, 'unsubscribe'])->name('unsubscribe');
        Route::post('/test', [NotificationsController::class, 'test'])->name('test');
    });
});
