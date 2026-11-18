<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Regions\Http\Controllers\CountriesController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/countries', [CountriesController::class, 'index'])->name('admin.countries');
        Route::get('/countries/{code}', [CountriesController::class, 'show'])->where('code', '[A-Za-z]{2}')->name('admin.countries.show');
        Route::put('/countries/{code}', [CountriesController::class, 'update'])->name('admin.countries.update');
        Route::post('/countries/{code}/rates', [CountriesController::class, 'addRate'])->name('admin.countries.rates.store');
        Route::post('/countries/{code}/rates/{rate}/confirm', [CountriesController::class, 'confirmRate'])->whereNumber('rate')->name('admin.countries.rates.confirm');
        Route::delete('/countries/{code}/rates/{rate}', [CountriesController::class, 'deleteRate'])->whereNumber('rate')->name('admin.countries.rates.destroy');
    });
