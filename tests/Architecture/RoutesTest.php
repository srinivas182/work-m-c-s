<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/*
| On the back-office host, "/{tenant}" routes are matched before host-agnostic
| routes (Laravel prefers routes with a domain). Every other path's first
| segment must therefore be a reserved handle, or a business could shadow it.
*/

it('reserves the first path segment of every platform route on the back-office host', function () {
    $app = (string) config('wurk.domains.app');
    $reserved = (array) config('wurk.tenancy.reserved_handles');
    $unreserved = [];

    foreach (Route::getRoutes() as $route) {
        $domain = $route->getDomain();
        if (($domain !== null && $domain !== $app) || str_starts_with($route->uri(), '{tenant}')) {
            continue;
        }

        $first = explode('/', $route->uri())[0];
        if ($first !== '' && ! str_starts_with($first, '_') && ! str_starts_with($first, '{') && ! in_array($first, $reserved, true)) {
            $unreserved[] = $route->uri();
        }
    }

    expect(array_values(array_unique($unreserved)))->toBe([]);
});

it('routes platform paths on the back-office host to the platform, not to a business', function (string $path, string $name) {
    $route = Route::getRoutes()->match(Request::create('http://'.config('wurk.domains.app').$path));

    expect($route->getName())->toBe($name);
})->with([
    ['/sign-in', 'identity.sign-in'],
    ['/businesses', 'identity.businesses'],
    ['/account/security', 'identity.account'],
    ['/forgot-password', 'identity.forgot'],
    ['/glow-salon', 'tenant.home'],
    ['/sign-in-studio', 'tenant.home'], // a real business whose handle merely starts with a reserved word
]);
