<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Wurk\Watchtower\Support\SlowPages;

/**
 * Times web requests (Sprint 18.0b): a small sample of every route — enough to tell when it stops answering within
 * the PRD's 300 ms for 95% of requests — plus every request over 2 seconds. Written after the response has gone.
 */
final class TimeRequests
{
    public const STARTED = 'watchtower.started';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED, hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get(self::STARTED);
        $route = $request->route();
        if (! is_int($started) || $route === null || ! is_object($route)) {
            return;
        }
        try {
            SlowPages::record($request->method().' '.$route->uri(), (int) round((hrtime(true) - $started) / 1e6));
        } catch (Throwable) {
            // timing must never break a request
        }
    }
}
