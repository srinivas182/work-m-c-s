<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Help\Http\Controllers\Admin\HelpAdminController;
use Wurk\Help\Http\Controllers\PublicHelpController;

// help.wurk.co.za — public.
Route::domain((string) config('wurk.domains.help'))->group(function (): void {
    Route::get('/', [PublicHelpController::class, 'home'])->name('help.home');
    Route::get('/search', [PublicHelpController::class, 'search'])->middleware('throttle:60,1,help-search')->name('help.search');
    Route::get('/c/{slug}', [PublicHelpController::class, 'category'])->where('slug', '[a-z0-9-]+')->name('help.category');
    Route::get('/a/{slug}', [PublicHelpController::class, 'article'])->where('slug', '[a-z0-9-]+')->name('help.article');
    Route::post('/a/{slug}/feedback', [PublicHelpController::class, 'feedback'])->where('slug', '[a-z0-9-]+')->middleware('throttle:30,1,help-feedback')->name('help.feedback');
});

// Admin → Help centre.
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff', 'permission:help.manage'])->prefix('help')->name('admin.help')->group(function (): void {
    Route::get('/', [HelpAdminController::class, 'index']);
    Route::post('/categories', [HelpAdminController::class, 'saveCategory'])->name('.categories');
    Route::get('/new', [HelpAdminController::class, 'create'])->name('.create');
    Route::post('/new', [HelpAdminController::class, 'store'])->name('.store');
    Route::get('/{article}', [HelpAdminController::class, 'edit'])->name('.edit');
    Route::post('/{article}', [HelpAdminController::class, 'update'])->name('.update');
    Route::post('/{article}/image', [HelpAdminController::class, 'image'])->middleware('throttle:30,1,help-image')->name('.image');
});
