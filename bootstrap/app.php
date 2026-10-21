<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Wurk\Identity\Auth\SignInFlow;
use Wurk\Identity\Http\Middleware\SessionGuardian;
use Wurk\Identity\Support\Surface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SessionGuardian::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('identity.sign-in'));
        $middleware->redirectUsersTo(fn (Request $request) => SignInFlow::home(Surface::of($request)));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
