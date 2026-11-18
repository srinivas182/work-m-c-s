<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Auth\SignInFlow;
use Wurk\Identity\Http\Middleware\SessionGuardian;
use Wurk\Identity\Support\Surface;
use Wurk\Platform\I18n\CustomerLocale;
use Wurk\Platform\I18n\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CustomerLocale::class); // customer pages in the customer's language (24.3)
        $middleware->web(append: [
            SessionGuardian::class,
            SetLocale::class, // the person's language (24.1)
            HandleInertiaRequests::class,
        ]);
        // The referral cookie holds only a public code, and is read on routes with and without the web stack (Sprint 13.2).
        $middleware->encryptCookies(except: ['wurk_ref', 'wurk_agent', 'wurk_lang', 'wurk_lite', 'wurk_slow']); // public codes only (Sprints 13.2, 14.2)
        $middleware->redirectGuestsTo(fn () => route('identity.sign-in'));
        $middleware->redirectUsersTo(fn (Request $request) => SignInFlow::home(Surface::of($request)));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // App screens: a friendly message on the same page instead of a raw "429" box.
        // API and JSON callers still get a proper 429 response.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->hasHeader('X-Inertia') && ! $request->expectsJson()) {
                return back()->with('warning', 'That was a lot of attempts in a short time. Please wait a minute and try again.');
            }

            return null;
        });
        // Moving around the app (an Inertia visit): the error is shown in place, in WURK's words, instead of the raw
        // error box Inertia shows for a non-Inertia response (18.6a). Full page loads get resources/views/errors.
        // In local development the detailed error page still shows for 500s.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if (! $request->hasHeader('X-Inertia') || $request->expectsJson() || ! in_array($status, [403, 404, 419, 500, 503], true)
                || ($status === 500 && config('app.debug'))) {
                return $response;
            }
            if ($status === 419) { // an expired form: back to it, with a message, rather than a dead end
                return back()->with('warning', 'This page timed out. Please try again.');
            }

            return Inertia::render('Error', ['status' => $status, 'message' => $status === 503 ? ($e->getMessage() ?: null) : null])
                ->toResponse($request)->setStatusCode($status);
        });
    })->create();
