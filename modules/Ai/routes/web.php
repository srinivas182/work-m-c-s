<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Ai\Http\Controllers\AiAdminController;
use Wurk\Ai\Http\Controllers\AssistantController;
use Wurk\Ai\Http\Controllers\LanguagesController;
use Wurk\Ai\Http\Controllers\SalesChatController;

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->prefix('settings/ai')->name('admin.ai')->group(function (): void {
    Route::get('/', [AiAdminController::class, 'show']);
    Route::put('/', [AiAdminController::class, 'update'])->name('.update');
    Route::post('/try', [AiAdminController::class, 'try'])->middleware('throttle:10,1,ai-try')->name('.try');
});

// "Ask WURK" (16.2): the assistant panel in every business's back office.
// WURK's sales assistant on wurk.co.za (19.5): like the public websites' chat, no session or CSRF (nothing signed in to
// protect); per-visitor limits and HQ's caps guard against abuse. The agent's link cookie is unencrypted (a public code).
Route::domain((string) config('wurk.domains.root'))->withoutMiddleware(['web'])->prefix('_sales')->name('marketing.sales')->group(function (): void {
    Route::get('/', [SalesChatController::class, 'config'])->middleware('throttle:60,1,sales-config');
    Route::post('/message', [SalesChatController::class, 'message'])->middleware('throttle:20,1,sales-message')->name('.message');
});

Route::tenant(function (): void {
    Route::prefix('_assistant')->name('tenant.assistant')->group(function (): void {
        Route::get('/', [AssistantController::class, 'show']);
        Route::post('/ask', [AssistantController::class, 'ask'])->middleware('throttle:20,1,assistant-ask')->name('.ask');
        Route::post('/actions/{action}/confirm', [AssistantController::class, 'confirm'])->middleware('throttle:30,1,assistant-act')->name('.confirm');
        Route::post('/actions/{action}/undo', [AssistantController::class, 'undo'])->middleware('throttle:30,1,assistant-act')->name('.undo');
        Route::post('/actions/{action}/cancel', [AssistantController::class, 'cancel'])->name('.cancel');
    });
});

// Admin → Languages (24.2): progress, WURK AI drafts and reviewing. Reviewers need languages.review; the rest languages.manage.
Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff', 'permission:languages.review'])->prefix('settings/languages')->name('admin.languages')->group(function (): void {
    Route::get('/', [LanguagesController::class, 'index']);
    Route::get('/{locale}', [LanguagesController::class, 'show'])->where('locale', '[a-z]{2}')->name('.show');
    Route::put('/{locale}', [LanguagesController::class, 'save'])->where('locale', '[a-z]{2}')->name('.save');
    Route::post('/{locale}/draft', [LanguagesController::class, 'draft'])->where('locale', '[a-z]{2}')->middleware('throttle:5,10,languages-draft')->name('.draft');
    Route::put('/{locale}/offered', [LanguagesController::class, 'toggle'])->where('locale', '[a-z]{2}')->name('.toggle');
    Route::post('/{locale}/glossary', [LanguagesController::class, 'glossary'])->where('locale', '[a-z]{2}')->name('.glossary');
    Route::post('/{locale}/captions', [LanguagesController::class, 'draftCaptions'])->where('locale', '[a-z]{2}')->middleware('throttle:5,10,languages-captions')->name('.captions');
    Route::put('/{locale}/captions/{article}', [LanguagesController::class, 'saveCaptions'])->where('locale', '[a-z]{2}')->name('.captions.save');
});
