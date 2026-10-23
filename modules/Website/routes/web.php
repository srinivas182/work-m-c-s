<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Website\Http\Controllers\AssetsController;
use Wurk\Website\Http\Controllers\BlockBoardController;
use Wurk\Website\Http\Controllers\BrandingController;
use Wurk\Website\Http\Controllers\DraftController;
use Wurk\Website\Http\Controllers\EditorController;
use Wurk\Website\Http\Controllers\MenusController;
use Wurk\Website\Http\Controllers\PagesController;
use Wurk\Website\Http\Controllers\PreviewController;
use Wurk\Website\Http\Controllers\TemplatePreviewController;

Route::get('/_blocks', BlockBoardController::class)->name('website.block-board');

Route::domain((string) config('wurk.domains.app'))->middleware('throttle:120,1,template-preview')
    ->get('/templates/{template}/preview', TemplatePreviewController::class)->name('website.template-preview');

Route::tenant(function (): void {
    Route::middleware(['entitled:website', 'permission:website.edit'])->prefix('website')->name('tenant.website.')->group(function (): void {
        Route::get('/pages', [PagesController::class, 'index'])->name('pages');
        Route::post('/pages', [PagesController::class, 'store'])->name('pages.store');
        Route::put('/pages/order', [PagesController::class, 'reorder'])->name('pages.reorder');
        Route::put('/pages/{page}', [PagesController::class, 'update'])->name('pages.update');
        Route::post('/pages/{page}/home', [PagesController::class, 'home'])->name('pages.home');
        Route::delete('/pages/{page}', [PagesController::class, 'destroy'])->name('pages.destroy');
        Route::post('/pages/{page}/restore', [PagesController::class, 'restore'])->name('pages.restore');

        Route::get('/pages/{page}/edit', EditorController::class)->name('editor');
        Route::get('/pages/{page}/draft', [DraftController::class, 'show'])->name('draft');
        Route::put('/pages/{page}/draft', [DraftController::class, 'save'])->middleware('throttle:120,1,website-draft')->name('draft.save');
        Route::get('/pages/{page}/versions', [DraftController::class, 'versions'])->name('versions');

        Route::get('/photos', [AssetsController::class, 'index'])->name('photos');
        Route::post('/photos', [AssetsController::class, 'store'])->middleware('throttle:60,1,website-photos')->name('photos.store');
        Route::get('/preview', PreviewController::class)->name('preview');
        Route::get('/menus', [MenusController::class, 'show'])->name('menus');
        Route::put('/menus', [MenusController::class, 'update'])->name('menus.update');
        Route::get('/branding', [BrandingController::class, 'show'])->name('branding');
        Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');

        Route::middleware('permission:website.publish')->group(function (): void {
            Route::post('/pages/{page}/publish', [DraftController::class, 'publish'])->name('publish');
            Route::post('/pages/{page}/unpublish', [DraftController::class, 'unpublish'])->name('unpublish');
            Route::post('/pages/{page}/versions/{version}/restore', [DraftController::class, 'restore'])->name('versions.restore');
        });
    });
});
