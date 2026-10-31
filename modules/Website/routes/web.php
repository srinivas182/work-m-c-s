<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Website\Http\Controllers\Admin\LaunchTimesController;
use Wurk\Website\Http\Controllers\Admin\TemplatesController;
use Wurk\Website\Http\Controllers\AiImageController;
use Wurk\Website\Http\Controllers\AiWriterController;
use Wurk\Website\Http\Controllers\AssetsController;
use Wurk\Website\Http\Controllers\BlockBoardController;
use Wurk\Website\Http\Controllers\BrandingController;
use Wurk\Website\Http\Controllers\DraftController;
use Wurk\Website\Http\Controllers\EditorController;
use Wurk\Website\Http\Controllers\EnquiriesController;
use Wurk\Website\Http\Controllers\MediaController;
use Wurk\Website\Http\Controllers\MenusController;
use Wurk\Website\Http\Controllers\PagesController;
use Wurk\Website\Http\Controllers\PreviewController;
use Wurk\Website\Http\Controllers\PublicSeoController;
use Wurk\Website\Http\Controllers\PublicSiteController;
use Wurk\Website\Http\Controllers\QuoteFormController;
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
        Route::post('/checklist/{step}', [PagesController::class, 'confirmStep'])->name('checklist.confirm');
        Route::post('/design', [PagesController::class, 'switchDesign'])->middleware('throttle:10,1,website-design')->name('design');

        Route::get('/pages/{page}/edit', EditorController::class)->name('editor');
        Route::get('/pages/{page}/draft', [DraftController::class, 'show'])->name('draft');
        Route::put('/pages/{page}/draft', [DraftController::class, 'save'])->middleware('throttle:120,1,website-draft')->name('draft.save');
        Route::get('/pages/{page}/versions', [DraftController::class, 'versions'])->name('versions');

        Route::get('/media', [MediaController::class, 'index'])->name('media');
        Route::put('/media/{asset}', [MediaController::class, 'update'])->name('media.update');
        Route::delete('/media/{asset}', [MediaController::class, 'destroy'])->name('media.destroy');
        Route::get('/photos', [AssetsController::class, 'index'])->name('photos');
        Route::post('/photos', [AssetsController::class, 'store'])->middleware('throttle:60,1,website-photos')->name('photos.store');
        Route::get('/preview', PreviewController::class)->name('preview');
        Route::get('/ai', [AiWriterController::class, 'show'])->name('ai');
        Route::get('/ai/quote', [AiWriterController::class, 'quote'])->name('ai.quote');
        Route::post('/ai/generate', [AiWriterController::class, 'generate'])->middleware('throttle:6,1,ai-generate')->name('ai.generate');
        Route::post('/ai/image', AiImageController::class)->middleware('throttle:10,1,ai-image')->name('ai.image');
        Route::get('/enquiries', [EnquiriesController::class, 'index'])->name('enquiries');
        Route::put('/enquiries/settings', [EnquiriesController::class, 'settings'])->name('enquiries.settings');
        Route::get('/enquiries/{enquiry}/files/{file}', [EnquiriesController::class, 'file'])->middleware('throttle:60,1,enquiry-files')->name('enquiries.file');
        Route::put('/enquiries/{enquiry}', [EnquiriesController::class, 'status'])->name('enquiries.status');
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

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->prefix('templates')->name('admin.templates')->group(function (): void {
    Route::get('/', [TemplatesController::class, 'index']);
    Route::post('/', [TemplatesController::class, 'store'])->name('.store');
    Route::put('/categories/{category}', [TemplatesController::class, 'updateCategory'])->name('.category');
    Route::get('/{template}', [TemplatesController::class, 'show'])->name('.show');
    Route::put('/{template}', [TemplatesController::class, 'update'])->name('.update');
    Route::post('/{template}/publish', [TemplatesController::class, 'publish'])->name('.publish');
    Route::post('/{template}/retire', [TemplatesController::class, 'retire'])->name('.retire');
    Route::get('/{template}/preview', [TemplatesController::class, 'preview'])->name('.preview');
    Route::get('/{template}/versions', [TemplatesController::class, 'versions'])->name('.versions');
    Route::post('/{template}/versions/{version}/restore', [TemplatesController::class, 'restoreVersion'])->name('.versions.restore');
    Route::get('/{template}/photos', [TemplatesController::class, 'photos'])->name('.photos');
    Route::post('/{template}/photos', [TemplatesController::class, 'uploadPhoto'])->middleware('throttle:60,1,template-photos')->name('.photos.store');
    Route::get('/{template}/pages/{page}/edit', [TemplatesController::class, 'editPage'])->name('.pages.edit');
    Route::put('/{template}/pages/{page}/draft', [TemplatesController::class, 'saveDraft'])->middleware('throttle:120,1,template-draft')->name('.pages.draft');
});

// Public quote forms: no session or CSRF (nothing signed in to protect); hidden field,
// per-visitor limit and a daily cap per business guard against spam.
Route::domain((string) config('wurk.domains.app'))->withoutMiddleware(['web'])
    ->middleware('throttle:quote-form')
    ->post('/forms/{handle}/quote', QuoteFormController::class)->name('website.forms.quote');

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])
    ->get('/launch-times', LaunchTimesController::class)->name('admin.launch-times');

// Public websites: {handle}.{root} (e.g. glow-salon.wurk.co.za; *.wurk.test in development and tests).
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->group(function (): void {
    // The quote form on the business's own address (same protections as the app-domain route: no session
    // or CSRF, a hidden spam field, a per-visitor limit and a daily cap per business).
    Route::post('/forms/quote', QuoteFormController::class)->middleware('throttle:quote-form')->name('website.public.quote');
    Route::get('/robots.txt', PublicSeoController::class)->defaults('type', 'robots')->name('website.public.robots');
    Route::get('/sitemap.xml', PublicSeoController::class)->defaults('type', 'sitemap')->name('website.public.sitemap');
    Route::get('/llms.txt', PublicSeoController::class)->defaults('type', 'llms')->name('website.public.llms');
    Route::get('/', PublicSiteController::class)->name('website.public.home');
    // A fallback, so more specific routes on business subdomains always win.
    Route::fallback(PublicSiteController::class)->name('website.public.page');
});
