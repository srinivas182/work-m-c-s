<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Records\Http\Controllers\PublicRecordsController;
use Wurk\Records\Http\Controllers\RecordsController;

// A patient's own documents, on the practice's address (no session: an encrypted cookie after the code).
Route::domain('{handle}.'.config('wurk.domains.root'))->withoutMiddleware(['web'])->prefix('records/{token}')->name('records.public.')
    ->where(['token' => '[A-Za-z0-9]{48}'])->group(function (): void {
        Route::get('/', [PublicRecordsController::class, 'show'])->middleware('throttle:records-read')->name('show');
        Route::post('/code', [PublicRecordsController::class, 'sendCode'])->middleware('throttle:records-code')->name('code');
        Route::post('/verify', [PublicRecordsController::class, 'verify'])->middleware('throttle:records-verify')->name('verify');
        Route::get('/{document}', [PublicRecordsController::class, 'file'])->middleware('throttle:records-read')->name('file');
    });

Route::tenant(function (): void {
    Route::middleware(['permission:records.view', 'entitled:bookings'])->prefix('records')->name('tenant.records')->group(function (): void {
        Route::get('/', [RecordsController::class, 'index']);
        Route::post('/', [RecordsController::class, 'store'])->name('.store');
        Route::get('/{patient}', [RecordsController::class, 'show'])->name('.show');
        Route::post('/{patient}/documents', [RecordsController::class, 'upload'])->middleware('throttle:30,1,records-upload')->name('.upload');
        Route::get('/{patient}/documents/{document}', [RecordsController::class, 'file'])->name('.file');
        Route::post('/{patient}/link', [RecordsController::class, 'sendLink'])->middleware('throttle:10,1,records-link')->name('.link');
        Route::delete('/{patient}/remove', [RecordsController::class, 'remove'])->name('.remove');
    });
});
