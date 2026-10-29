<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Team\Http\Controllers\BranchesController;
use Wurk\Team\Http\Controllers\SettlementsController;
use Wurk\Team\Http\Controllers\TeamController;

Route::tenant(function (): void {
    Route::middleware(['permission:branches.view'])->prefix('settings/branches')->name('tenant.branches')->group(function (): void {
        Route::get('/', [BranchesController::class, 'index']);
        Route::get('/settlements', [SettlementsController::class, 'index'])->name('.settlements');
        Route::post('/settlements/settle', [SettlementsController::class, 'settle'])->name('.settle');
        Route::post('/', [BranchesController::class, 'store'])->name('.store');
        Route::put('/{branch}', [BranchesController::class, 'update'])->name('.update');
        Route::put('/{branch}/closed', [BranchesController::class, 'close'])->name('.close');
    });
});

Route::tenant(function (): void {
    Route::middleware(['permission:team.view'])->prefix('settings/team')->name('tenant.team')->group(function (): void {
        Route::get('/', [TeamController::class, 'index']);
        Route::post('/invitations', [TeamController::class, 'invite'])->middleware('throttle:20,1,team-invite')->name('.invite');
        Route::delete('/invitations/{invitation}', [TeamController::class, 'revokeInvite'])->name('.revoke');
        Route::put('/{member}/role', [TeamController::class, 'role'])->name('.role');
        Route::put('/{member}/branches', [TeamController::class, 'branches'])->name('.branches');
        Route::delete('/{member}', [TeamController::class, 'remove'])->name('.remove');
    });
});
