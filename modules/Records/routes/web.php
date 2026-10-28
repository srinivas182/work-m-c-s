<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Records\Http\Controllers\PublicRecordsController;

// A patient's own documents, on the practice's address (no session: an encrypted cookie after the code).
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->prefix('records/{token}')->name('records.public.')
    ->where(['token' => '[A-Za-z0-9]{48}'])->group(function (): void {
        Route::get('/', [PublicRecordsController::class, 'show'])->middleware('throttle:60,1')->name('show');
        Route::post('/code', [PublicRecordsController::class, 'sendCode'])->middleware('throttle:5,10')->name('code');
        Route::post('/verify', [PublicRecordsController::class, 'verify'])->middleware('throttle:10,10')->name('verify');
        Route::get('/{document}', [PublicRecordsController::class, 'file'])->middleware('throttle:60,1')->name('file');
    });
