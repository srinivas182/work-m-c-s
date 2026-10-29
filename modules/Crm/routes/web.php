<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Crm\Http\Controllers\ContactsController;

Route::tenant(function (): void {
    Route::middleware(['permission:contacts.view'])->prefix('contacts')->name('tenant.contacts')->group(function (): void {
        Route::get('/', [ContactsController::class, 'index']);
        Route::get('/export', [ContactsController::class, 'export'])->name('.export');
        Route::get('/search', [ContactsController::class, 'search'])->name('.search');
        Route::get('/{contact}', [ContactsController::class, 'show'])->name('.show');
        Route::put('/{contact}', [ContactsController::class, 'update'])->name('.update');
        Route::post('/{contact}/merge', [ContactsController::class, 'merge'])->name('.merge');
        Route::post('/{contact}/split/{merged}', [ContactsController::class, 'split'])->name('.split');
    });
});
