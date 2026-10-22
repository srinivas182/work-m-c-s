<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Marketing\Http\Controllers\MarketingController;

Route::domain((string) config('wurk.domains.root'))->group(function (): void {
    Route::get('/', [MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/faq', [MarketingController::class, 'faq'])->name('marketing.faq');
    Route::get('/contact', [MarketingController::class, 'contact'])->name('marketing.contact');
    Route::post('/contact', [MarketingController::class, 'sendContact'])->middleware('throttle:5,10')->name('marketing.contact.send');
    Route::get('/legal/{page}', [MarketingController::class, 'legal'])->name('marketing.legal');
});
