<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Identity\Http\Controllers\AccountController;
use Wurk\Identity\Http\Controllers\AdminHomeController;
use Wurk\Identity\Http\Controllers\BusinessesController;
use Wurk\Identity\Http\Controllers\MfaController;
use Wurk\Identity\Http\Controllers\PasswordResetController;
use Wurk\Identity\Http\Controllers\SignInController;
use Wurk\Identity\Http\Controllers\WorkspaceController;
use Wurk\Identity\Http\Middleware\EnsurePlatformStaff;
use Wurk\Identity\Http\Middleware\EnsureTenantMember;

Route::middleware('guest')->group(function (): void {
    Route::get('/sign-in', [SignInController::class, 'show'])->name('identity.sign-in');
    Route::post('/sign-in/code', [SignInController::class, 'sendCode'])->name('identity.code.send');
    Route::get('/sign-in/code', [SignInController::class, 'showCode'])->name('identity.code');
    Route::post('/sign-in/code/verify', [SignInController::class, 'verifyCode'])->name('identity.code.verify');
    Route::get('/sign-in/password', [SignInController::class, 'showPassword'])->name('identity.password');
    Route::post('/sign-in/password', [SignInController::class, 'password'])->name('identity.password.attempt');
    Route::get('/sign-in/mfa', [MfaController::class, 'show'])->name('identity.mfa');
    Route::post('/sign-in/mfa', [MfaController::class, 'verify'])->name('identity.mfa.verify');
    Route::get('/sign-in/mfa/setup', [MfaController::class, 'setup'])->name('identity.mfa.setup');
    Route::post('/sign-in/mfa/setup', [MfaController::class, 'confirmSetup'])->name('identity.mfa.setup.confirm');
    Route::post('/sign-in/mfa/continue', [MfaController::class, 'continueAfterSetup'])->name('identity.mfa.continue');

    Route::get('/forgot-password', [PasswordResetController::class, 'show'])->name('identity.forgot');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])->name('identity.forgot.send');
    Route::get('/forgot-password/code', [PasswordResetController::class, 'showCode'])->name('identity.forgot.code');
    Route::post('/forgot-password/code', [PasswordResetController::class, 'verifyCode'])->name('identity.forgot.verify');
    Route::get('/forgot-password/new', [PasswordResetController::class, 'showNew'])->name('identity.forgot.new');
    Route::post('/forgot-password/new', [PasswordResetController::class, 'reset'])->name('identity.forgot.reset');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/sign-out', [AccountController::class, 'signOut'])->name('identity.sign-out');
    Route::get('/account/security', [AccountController::class, 'show'])->name('identity.account');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('identity.account.profile');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('identity.account.password');
    Route::post('/account/mfa', [AccountController::class, 'beginMfa'])->name('identity.account.mfa.begin');
    Route::post('/account/mfa/confirm', [AccountController::class, 'confirmMfa'])->name('identity.account.mfa.confirm');
    Route::delete('/account/mfa', [AccountController::class, 'disableMfa'])->name('identity.account.mfa.disable');
    Route::delete('/account/sessions', [AccountController::class, 'revokeOtherSessions'])->name('identity.account.sessions.revoke');
    Route::get('/businesses', BusinessesController::class)->name('identity.businesses');
});

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', EnsurePlatformStaff::class])
    ->group(function (): void {
        Route::get('/dashboard', AdminHomeController::class)->name('admin.home');
    });

Route::tenant(function (): void {
    Route::get('/', WorkspaceController::class)->middleware(EnsureTenantMember::class)->name('tenant.home');
});
