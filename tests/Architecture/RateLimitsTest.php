<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/*
| Sprint 18.1: every route that changes something and can be reached without signing in (sign-in, codes, forms,
| bookings, payments notifications, webhooks, crash reports) must be rate-limited. A new one without a throttle
| fails here, before it reaches production.
*/
it('rate-limits every public route that changes something', function () {
    $open = [];
    foreach (Route::getRoutes() as $route) {
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }
        $middleware = Route::gatherRouteMiddleware($route);
        $signedIn = array_filter($middleware, fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate')));
        $throttled = array_filter($middleware, fn ($m) => is_string($m) && (str_starts_with($m, 'throttle') || str_contains($m, 'ThrottleRequests')));
        if ($signedIn === [] && $throttled === []) {
            $open[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
        }
    }

    expect($open)->toBe([]);
});
