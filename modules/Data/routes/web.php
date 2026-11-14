<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Data\Http\Controllers\YourDataController;

// Settings → Your data (16.6).
Route::tenant(function (): void {
    Route::middleware('permission:data.export')->prefix('settings/your-data')->name('tenant.data')->group(function (): void {
        Route::get('/', [YourDataController::class, 'show']);
        Route::post('/export', [YourDataController::class, 'store'])->middleware('throttle:5,60,data-export')->name('.export');
        Route::get('/export/{export}', [YourDataController::class, 'download'])->middleware('signed')->name('.download');
    });
});
