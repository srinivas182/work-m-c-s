<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Team\Http\Controllers\BranchesController;

Route::tenant(function (): void {
    Route::middleware(['permission:branches.view'])->prefix('settings/branches')->name('tenant.branches')->group(function (): void {
        Route::get('/', [BranchesController::class, 'index']);
        Route::post('/', [BranchesController::class, 'store'])->name('.store');
        Route::put('/{branch}', [BranchesController::class, 'update'])->name('.update');
        Route::put('/{branch}/closed', [BranchesController::class, 'close'])->name('.close');
    });
});
