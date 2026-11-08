<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Agents\Http\Controllers\Admin\AgentsController;
use Wurk\Agents\Http\Controllers\Admin\BusinessAgentController;
use Wurk\Agents\Http\Controllers\Admin\CommissionController;
use Wurk\Agents\Http\Controllers\AgentLinkController;

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->name('admin.')->group(function (): void {
    Route::get('/agents/commission', [CommissionController::class, 'show'])->name('agents.commission');
    Route::put('/agents/commission', [CommissionController::class, 'update'])->name('agents.commission.update');
    Route::get('/agents', [AgentsController::class, 'index'])->name('agents.index');
    Route::post('/agents', [AgentsController::class, 'store'])->name('agents.store');
    Route::put('/agents/{agent}', [AgentsController::class, 'update'])->name('agents.update');
    Route::get('/agents/{agent}/qr.svg', [AgentsController::class, 'qr'])->name('agents.qr');
    Route::get('/businesses/{tenant}/agent', [BusinessAgentController::class, 'show'])->name('businesses.agent');
    Route::put('/businesses/{tenant}/agent', [BusinessAgentController::class, 'update'])->name('businesses.agent.update');
});

// An agent's link (Sprint 14.2): wurk.co.za/a/CODE.
Route::domain((string) config('wurk.domains.root'))->middleware('throttle:60,1,agent-links')
    ->get('/a/{code}', AgentLinkController::class)->where('code', '[A-Za-z0-9]{7}')->name('agents.link');
