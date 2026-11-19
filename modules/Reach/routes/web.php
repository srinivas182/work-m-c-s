<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Reach\Http\Controllers\UssdController;

// WURK's USSD code (25.2): the gateway posts each step here — no session or CSRF; a secret token in the address.
Route::domain((string) config('wurk.domains.app'))
    ->withoutMiddleware(['web'])
    ->middleware('throttle:1200,1')
    ->post('/webhooks/ussd/{provider}/{token}', UssdController::class)
    ->where(['provider' => '[a-z]+', 'token' => '[A-Za-z0-9_-]{16,100}'])
    ->name('webhooks.ussd');
