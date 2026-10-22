<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Tenancy\Http\Controllers\Admin\BusinessesController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->prefix('businesses')->name('admin.businesses.')
    ->group(function (): void {
        Route::get('/', [BusinessesController::class, 'index'])->name('index');
        Route::get('/new', [BusinessesController::class, 'create'])->name('create');
        Route::get('/check-handle', [BusinessesController::class, 'checkHandle'])->name('check-handle');
        Route::post('/', [BusinessesController::class, 'store'])->name('store');
        Route::get('/{tenant}', [BusinessesController::class, 'show'])->name('show');
        Route::post('/{tenant}/suspend', [BusinessesController::class, 'suspend'])->name('suspend');
        Route::post('/{tenant}/reactivate', [BusinessesController::class, 'reactivate'])->name('reactivate');
    });
