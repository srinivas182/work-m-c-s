<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Shipping\Http\Controllers\ShippingSettingsController;

Route::tenant(function (): void {
    Route::middleware(['entitled:shop', 'permission:shipping.manage'])->prefix('settings/shipping')->name('tenant.shipping')->group(function (): void {
        Route::get('/', [ShippingSettingsController::class, 'index']);
        Route::put('/{provider}/keys/{mode}', [ShippingSettingsController::class, 'keys'])->middleware('throttle:10,1,shipping-keys')->name('.keys');
        Route::put('/{provider}', [ShippingSettingsController::class, 'update'])->name('.update');
        Route::delete('/{provider}', [ShippingSettingsController::class, 'disconnect'])->name('.disconnect');
    });
});
