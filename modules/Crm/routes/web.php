<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Crm\Http\Controllers\ContactsController;
use Wurk\Crm\Http\Controllers\LeadsController;

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

Route::tenant(function (): void {
    Route::middleware(['permission:leads.view'])->prefix('leads')->name('tenant.leads')->group(function (): void {
        Route::get('/', [LeadsController::class, 'index']);
        Route::post('/', [LeadsController::class, 'store'])->name('.store');
        Route::put('/stages', [LeadsController::class, 'stagesUpdate'])->name('.stages');
        Route::get('/{lead}', [LeadsController::class, 'show'])->name('.show');
        Route::post('/{lead}/move', [LeadsController::class, 'move'])->name('.move');
        Route::post('/{lead}/assign', [LeadsController::class, 'assign'])->name('.assign');
        Route::post('/{lead}/follow-up', [LeadsController::class, 'followUp'])->name('.follow-up');
        Route::post('/{lead}/note', [LeadsController::class, 'note'])->name('.note');
    });
});
