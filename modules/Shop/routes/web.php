<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Shop\Http\Controllers\OrdersController;
use Wurk\Shop\Http\Controllers\PhotosController;
use Wurk\Shop\Http\Controllers\ProductsController;
use Wurk\Shop\Http\Controllers\PublicCheckoutController;
use Wurk\Shop\Http\Controllers\ShopSettingsController;

Route::tenant(function (): void {
    // Orders: owners and branch managers (who see only their branches' orders).
    Route::middleware(['entitled:shop', 'permission:shop.orders'])->prefix('shop')->name('tenant.shop.')->group(function (): void {
        Route::get('/orders', [OrdersController::class, 'index'])->name('orders');
        Route::get('/orders/{order}', [OrdersController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/advance', [OrdersController::class, 'advance'])->name('orders.advance');
        Route::post('/orders/{order}/cancel', [OrdersController::class, 'cancel'])->name('orders.cancel');
        Route::post('/orders/{order}/refund', [OrdersController::class, 'refund'])->name('orders.refund');
    });
    Route::middleware(['entitled:shop', 'permission:shop.manage'])->prefix('shop')->name('tenant.shop.')->group(function (): void {
        Route::get('/products', [ProductsController::class, 'index'])->name('products');
        Route::post('/products', [ProductsController::class, 'save'])->name('products.store');
        Route::put('/products/{product}', [ProductsController::class, 'save'])->name('products.update');
        Route::delete('/products/{product}', [ProductsController::class, 'destroy'])->name('products.destroy');
        Route::get('/settings', [ShopSettingsController::class, 'show'])->name('settings');
        Route::put('/settings', [ShopSettingsController::class, 'save'])->name('settings.save');
        Route::get('/photos', [PhotosController::class, 'index'])->name('photos');
        Route::post('/photos', [PhotosController::class, 'store'])->middleware('throttle:60,1,shop-photos')->name('photos.store');
        Route::post('/categories', [ProductsController::class, 'addCategory'])->name('categories.store');
        Route::delete('/categories/{category}', [ProductsController::class, 'removeCategory'])->name('categories.destroy');
        Route::get('/photos', [PhotosController::class, 'index'])->name('photos');
        Route::post('/photos', [PhotosController::class, 'store'])->middleware('throttle:60,1,shop-photos')->name('photos.store');
    });
});

// The cart and checkout on the business's own address (limits per business and visitor).
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->name('shop.public.')->group(function (): void {
    Route::post('/cart/price', [PublicCheckoutController::class, 'price'])->middleware('throttle:shop-read')->name('price');
    Route::post('/cart/checkout', [PublicCheckoutController::class, 'checkout'])->middleware('throttle:shop-create')->name('checkout');
    Route::get('/order/{order}', [PublicCheckoutController::class, 'order'])->middleware(['signed', 'throttle:shop-read'])->name('order');
});
