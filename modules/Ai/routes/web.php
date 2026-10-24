<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Ai\Http\Controllers\AiAdminController;

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->prefix('settings/ai')->name('admin.ai')->group(function (): void {
    Route::get('/', [AiAdminController::class, 'show']);
    Route::put('/', [AiAdminController::class, 'update'])->name('.update');
    Route::post('/try', [AiAdminController::class, 'try'])->middleware('throttle:10,1,ai-try')->name('.try');
});
