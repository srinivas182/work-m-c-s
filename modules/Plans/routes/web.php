<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Plans\Http\Controllers\Admin\BusinessPlanController;
use Wurk\Plans\Http\Controllers\Admin\PackagesController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->name('admin.')
    ->group(function (): void {
        Route::get('/packages', [PackagesController::class, 'index'])->name('packages.index');
        Route::get('/packages/new', [PackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [PackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}', [PackagesController::class, 'edit'])->name('packages.edit');
        Route::put('/packages/{package}', [PackagesController::class, 'update'])->name('packages.update');
        Route::post('/packages/{package}/archive', [PackagesController::class, 'archive'])->name('packages.archive');

        Route::get('/businesses/{tenant}/plan', [BusinessPlanController::class, 'show'])->name('businesses.plan');
        Route::put('/businesses/{tenant}/plan', [BusinessPlanController::class, 'assign'])->name('businesses.plan.assign');
        Route::post('/businesses/{tenant}/plan/modules', [BusinessPlanController::class, 'override'])->name('businesses.plan.override');
    });
