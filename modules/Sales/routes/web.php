<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Sales\Http\Controllers\SalesController;

Route::tenant(function (): void {
    Route::middleware(['entitled:crm'])->group(function (): void {
        Route::middleware('permission:sales.record')->prefix('sales')->name('tenant.sales')->group(function (): void {
            Route::get('/', [SalesController::class, 'index']);
            Route::get('/find', [SalesController::class, 'find'])->middleware('throttle:60,1,sales-find')->name('.find');
            Route::post('/identify', [SalesController::class, 'identify'])->middleware('throttle:60,1,sales-identify')->name('.identify');
            Route::post('/', [SalesController::class, 'store'])->name('.store');
        });
        Route::middleware('permission:sales.approve')->prefix('sales/{sale}')->name('tenant.sales')->group(function (): void {
            Route::post('/approve', [SalesController::class, 'approve'])->name('.approve');
            Route::post('/decline', [SalesController::class, 'decline'])->name('.decline');
            Route::post('/refund', [SalesController::class, 'refund'])->name('.refund');
        });
        Route::put('/settings/sales', [SalesController::class, 'threshold'])->middleware('permission:sales.manage')->name('tenant.sales.threshold');
    });
});
