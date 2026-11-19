<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Loyalty\Http\Controllers\LoyaltyController;

Route::tenant(function (): void {
    Route::middleware('permission:loyalty.manage')->prefix('customers/loyalty')->name('tenant.loyalty')->group(function (): void {
        Route::get('/', [LoyaltyController::class, 'show']);
        Route::put('/', [LoyaltyController::class, 'update'])->name('.update');
    });
});
