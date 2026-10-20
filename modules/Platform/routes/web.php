<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Wurk\Platform\Http\Controllers\WelcomeController;

Route::get('/', WelcomeController::class)->name('home');
