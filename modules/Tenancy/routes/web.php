<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Tenancy\Http\Controllers\Admin\BusinessesController;
use Wurk\Tenancy\Http\Controllers\Admin\DomainsController;

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

// Admin → Domains (Sprint 17.3): owners' own domains across WURK.
Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->prefix('domains')->name('admin.domains')
    ->group(function (): void {
        Route::get('/', [DomainsController::class, 'index']);
        Route::post('/{domain}/check', [DomainsController::class, 'check'])->middleware('throttle:30,1')->name('.check');
    });
