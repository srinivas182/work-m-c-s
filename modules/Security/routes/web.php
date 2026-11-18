<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Security\Http\Controllers\SecurityController;
use Wurk\Security\Http\Controllers\SecurityTxtController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff'])
    ->group(function (): void {
        Route::get('/security', [SecurityController::class, 'platform'])->name('admin.security');
        Route::put('/security', [SecurityController::class, 'updatePlatform'])->name('admin.security.update');
        Route::put('/security/contacts', [SecurityController::class, 'updateContacts'])->name('admin.security.contacts'); // 19.6
    });

Route::tenant(function (): void {
    Route::middleware('permission:team-security.manage')->group(function (): void {
        Route::get('/settings/security', [SecurityController::class, 'business'])->name('tenant.security');
        Route::put('/settings/security', [SecurityController::class, 'updateBusiness'])->name('tenant.security.update');
    });
});

// /.well-known/security.txt on each of WURK's own addresses (18.1b) — not on businesses' websites.
foreach (array_unique(array_filter([(string) config('wurk.domains.root'), (string) config('wurk.domains.app'), (string) config('wurk.domains.admin'),
    (string) config('wurk.domains.my'), (string) config('wurk.domains.help')])) as $i => $host) {
    Route::domain($host)->get('/.well-known/security.txt', SecurityTxtController::class)->name('security.txt.'.$i);
}
