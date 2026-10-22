<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Audit\Http\Controllers\AuditLogController;

Route::domain((string) config('wurk.domains.admin'))
    ->middleware(['auth', 'staff', 'permission:platform-audit.view'])
    ->get('/audit', AuditLogController::class)->name('admin.audit');

Route::tenant(function (): void {
    Route::get('/audit', AuditLogController::class)->middleware('permission:audit.view')->name('tenant.audit');
});
