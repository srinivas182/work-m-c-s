<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Platform\Http\Controllers\DesignSystemController;
use Wurk\Platform\Http\Controllers\WelcomeController;
use Wurk\Platform\I18n\TranslationsController;

Route::get('/', WelcomeController::class)->name('home');

// A language's translations for the browser (24.1): versioned, cached by the browser until they change.
Route::get('/i18n/{code}.{version}.js', TranslationsController::class)->where(['code' => '[a-z]{2}', 'version' => '[a-z0-9]{1,12}'])
    ->withoutMiddleware(['web'])->name('platform.i18n');

if (config('wurk.design_showcase')) {
    Route::get('/_design', DesignSystemController::class)->name('platform.design');
}
