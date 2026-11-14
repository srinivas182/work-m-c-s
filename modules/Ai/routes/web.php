<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Ai\Http\Controllers\AiAdminController;
use Wurk\Ai\Http\Controllers\AssistantController;

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->prefix('settings/ai')->name('admin.ai')->group(function (): void {
    Route::get('/', [AiAdminController::class, 'show']);
    Route::put('/', [AiAdminController::class, 'update'])->name('.update');
    Route::post('/try', [AiAdminController::class, 'try'])->middleware('throttle:10,1,ai-try')->name('.try');
});

// "Ask WURK" (16.2): the assistant panel in every business's back office.
Route::tenant(function (): void {
    Route::prefix('_assistant')->name('tenant.assistant')->group(function (): void {
        Route::get('/', [AssistantController::class, 'show']);
        Route::post('/ask', [AssistantController::class, 'ask'])->middleware('throttle:20,1,assistant-ask')->name('.ask');
        Route::post('/actions/{action}/confirm', [AssistantController::class, 'confirm'])->middleware('throttle:30,1,assistant-act')->name('.confirm');
        Route::post('/actions/{action}/undo', [AssistantController::class, 'undo'])->middleware('throttle:30,1,assistant-act')->name('.undo');
        Route::post('/actions/{action}/cancel', [AssistantController::class, 'cancel'])->name('.cancel');
    });
});
