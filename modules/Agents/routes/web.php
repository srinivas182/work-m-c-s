<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Agents\Http\Controllers\Admin\AgentsController;

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->name('admin.')->group(function (): void {
    Route::get('/agents', [AgentsController::class, 'index'])->name('agents.index');
    Route::post('/agents', [AgentsController::class, 'store'])->name('agents.store');
    Route::put('/agents/{agent}', [AgentsController::class, 'update'])->name('agents.update');
});
