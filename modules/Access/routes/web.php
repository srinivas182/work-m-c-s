<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Access\Http\Controllers\Admin\PlatformRolesController;
use Wurk\Access\Http\Controllers\Admin\StaffController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->name('admin.')
    ->group(function (): void {
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/staff/invitations', [StaffController::class, 'invite'])->name('staff.invite');
        Route::delete('/staff/invitations/{invitation}', [StaffController::class, 'revokeInvitation'])->name('staff.invitations.revoke');
        Route::put('/staff/{identity}/roles', [StaffController::class, 'updateRoles'])->name('staff.roles');
        Route::post('/staff/{identity}/deactivate', [StaffController::class, 'deactivate'])->name('staff.deactivate');
        Route::post('/staff/{identity}/reactivate', [StaffController::class, 'reactivate'])->name('staff.reactivate');

        Route::get('/roles', [PlatformRolesController::class, 'index'])->name('roles.index');
        Route::post('/roles', [PlatformRolesController::class, 'store'])->name('roles.store');
        Route::delete('/roles/{role}', [PlatformRolesController::class, 'destroy'])->name('roles.destroy');
    });
