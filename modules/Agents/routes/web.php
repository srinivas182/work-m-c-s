<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Agents\Http\Controllers\Admin\AgentsController;
use Wurk\Agents\Http\Controllers\Admin\BusinessAgentController;
use Wurk\Agents\Http\Controllers\Admin\CommissionController;
use Wurk\Agents\Http\Controllers\Admin\PayoutsController;
use Wurk\Agents\Http\Controllers\AgentAreaController;
use Wurk\Agents\Http\Controllers\AgentLinkController;

Route::domain((string) config('wurk.domains.admin'))->middleware(['auth', 'staff'])->name('admin.')->group(function (): void {
    Route::get('/agents/commission', [CommissionController::class, 'show'])->name('agents.commission');
    Route::put('/agents/commission', [CommissionController::class, 'update'])->name('agents.commission.update');
    Route::get('/agents/payouts', [PayoutsController::class, 'index'])->name('agents.payouts');
    Route::put('/agents/payouts/minimum', [PayoutsController::class, 'minimum'])->name('agents.payouts.minimum');
    Route::post('/agents/payouts', [PayoutsController::class, 'store'])->name('agents.payouts.store');
    Route::get('/agents/payouts/{batch}/csv', [PayoutsController::class, 'csv'])->name('agents.payouts.csv');
    Route::post('/agents/payouts/{batch}/cancel', [PayoutsController::class, 'cancel'])->name('agents.payouts.cancel');
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

// The agent area (Sprint 14.7; completed in 14.6): app.wurk.co.za/agent — agents only.
Route::domain((string) config('wurk.domains.app'))->middleware(['web', 'auth'])->prefix('agent')->name('agent.')->group(function (): void {
    Route::get('/', [AgentAreaController::class, 'home'])->name('home');
    Route::get('/bank', [AgentAreaController::class, 'bank'])->name('bank');
    Route::post('/bank', [AgentAreaController::class, 'request'])->middleware('throttle:5,10,agent-bank')->name('bank.request');
    Route::post('/bank/confirm', [AgentAreaController::class, 'confirm'])->middleware('throttle:10,10,agent-bank-confirm')->name('bank.confirm');
});
