<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Shop\Http\Controllers\PhotosController;
use Wurk\Shop\Http\Controllers\ProductsController;

Route::tenant(function (): void {
    Route::middleware(['entitled:shop', 'permission:shop.manage'])->prefix('shop')->name('tenant.shop.')->group(function (): void {
        Route::get('/products', [ProductsController::class, 'index'])->name('products');
        Route::post('/products', [ProductsController::class, 'save'])->name('products.store');
        Route::put('/products/{product}', [ProductsController::class, 'save'])->name('products.update');
        Route::delete('/products/{product}', [ProductsController::class, 'destroy'])->name('products.destroy');
        Route::get('/photos', [PhotosController::class, 'index'])->name('photos');
        Route::post('/photos', [PhotosController::class, 'store'])->middleware('throttle:60,1,shop-photos')->name('photos.store');
        Route::post('/categories', [ProductsController::class, 'addCategory'])->name('categories.store');
        Route::delete('/categories/{category}', [ProductsController::class, 'removeCategory'])->name('categories.destroy');
        Route::get('/photos', [PhotosController::class, 'index'])->name('photos');
        Route::post('/photos', [PhotosController::class, 'store'])->middleware('throttle:60,1,shop-photos')->name('photos.store');
    });
});
