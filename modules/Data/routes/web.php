<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Data\Http\Controllers\RecentlyDeletedController;
use Wurk\Data\Http\Controllers\YourDataController;

// Settings → Your data (16.6).
Route::tenant(function (): void {
    Route::middleware('permission:data.export')->prefix('settings/your-data')->name('tenant.data')->group(function (): void {
        Route::get('/', [YourDataController::class, 'show']);
        Route::post('/export', [YourDataController::class, 'store'])->middleware('throttle:5,60,data-export')->name('.export');
        Route::get('/export/{export}', [YourDataController::class, 'download'])->middleware('signed')->name('.download');
    });
    // Recently deleted (16.6b): each kind is limited by its own permission.
    Route::get('/settings/recently-deleted', [RecentlyDeletedController::class, 'show'])->name('tenant.data.deleted');
    Route::post('/settings/recently-deleted/{type}/{id}', [RecentlyDeletedController::class, 'restore'])->where('type', '[a-z-]+')->middleware('throttle:60,1,data-restore')->name('tenant.data.restore');
});
