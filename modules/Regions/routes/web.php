<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Regions\Http\Controllers\CountriesController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/countries', [CountriesController::class, 'index'])->name('admin.countries');
        Route::put('/countries/{code}', [CountriesController::class, 'update'])->name('admin.countries.update');
    });
