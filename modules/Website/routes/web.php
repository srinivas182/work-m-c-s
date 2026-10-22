<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Website\Http\Controllers\BrandingController;
use Wurk\Website\Http\Controllers\DraftController;
use Wurk\Website\Http\Controllers\PagesController;

Route::tenant(function (): void {
    Route::middleware(['entitled:website', 'permission:website.edit'])->prefix('website')->name('tenant.website.')->group(function (): void {
        Route::get('/pages', [PagesController::class, 'index'])->name('pages');
        Route::post('/pages', [PagesController::class, 'store'])->name('pages.store');
        Route::put('/pages/order', [PagesController::class, 'reorder'])->name('pages.reorder');
        Route::put('/pages/{page}', [PagesController::class, 'update'])->name('pages.update');
        Route::post('/pages/{page}/home', [PagesController::class, 'home'])->name('pages.home');
        Route::delete('/pages/{page}', [PagesController::class, 'destroy'])->name('pages.destroy');
        Route::post('/pages/{page}/restore', [PagesController::class, 'restore'])->name('pages.restore');

        Route::get('/pages/{page}/draft', [DraftController::class, 'show'])->name('draft');
        Route::put('/pages/{page}/draft', [DraftController::class, 'save'])->middleware('throttle:120,1,website-draft')->name('draft.save');
        Route::get('/pages/{page}/versions', [DraftController::class, 'versions'])->name('versions');

        Route::get('/branding', [BrandingController::class, 'show'])->name('branding');
        Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');

        Route::middleware('permission:website.publish')->group(function (): void {
            Route::post('/pages/{page}/publish', [DraftController::class, 'publish'])->name('publish');
            Route::post('/pages/{page}/unpublish', [DraftController::class, 'unpublish'])->name('unpublish');
            Route::post('/pages/{page}/versions/{version}/restore', [DraftController::class, 'restore'])->name('versions.restore');
        });
    });
});
