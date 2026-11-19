<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Reviews\Http\Controllers\PublicReviewController;
use Wurk\Reviews\Http\Controllers\ReviewsController;

// The customer's "How was it?" page on the business's address (25.3): signed links, no session.
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->middleware(['signed', 'throttle:30,1,reviews-public'])
    ->prefix('review')->name('reviews.public.')->group(function (): void {
        Route::get('/{request}', [PublicReviewController::class, 'show'])->whereUlid('request')->name('show');
        Route::post('/{request}', [PublicReviewController::class, 'store'])->whereUlid('request')->name('store');
    });

// Customers → Reviews (25.3).
Route::tenant(function (): void {
    Route::middleware(['permission:reviews.manage'])->prefix('reviews')->name('tenant.reviews')->group(function (): void {
        Route::get('/', [ReviewsController::class, 'index']);
        Route::put('/settings', [ReviewsController::class, 'settings'])->name('.settings');
        Route::put('/{review}/reply', [ReviewsController::class, 'reply'])->whereUlid('review')->name('.reply');
        Route::put('/{review}/hide', [ReviewsController::class, 'hide'])->whereUlid('review')->name('.hide');
        Route::put('/{review}/show', [ReviewsController::class, 'unhide'])->whereUlid('review')->name('.show');
    });
});
