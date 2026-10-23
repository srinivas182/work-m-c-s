<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Onboarding\Http\Controllers\SignupController;

Route::domain((string) config('wurk.domains.app'))
    ->middleware('throttle:signup')
    ->prefix('signup')->name('signup.')
    ->group(function (): void {
        Route::get('/', [SignupController::class, 'show'])->name('show');
        Route::post('/details', [SignupController::class, 'details'])->middleware('throttle:signup-start')->name('details');
        Route::post('/verify', [SignupController::class, 'verify'])->name('verify');
        Route::post('/resend', [SignupController::class, 'resend'])->name('resend');
        Route::post('/package', [SignupController::class, 'package'])->name('package');
        Route::post('/category', [SignupController::class, 'category'])->name('category');
        Route::post('/template', [SignupController::class, 'template'])->name('template');
        Route::get('/check-handle', [SignupController::class, 'checkHandle'])->name('check-handle');
        Route::post('/address', [SignupController::class, 'address'])->name('address');
        Route::post('/back/{step}', [SignupController::class, 'back'])->name('back');
        Route::post('/complete', [SignupController::class, 'complete'])->name('complete');
    });
