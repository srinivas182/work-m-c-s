<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Illuminate\Http\Request;

/** Where a problem happened (Sprint 18.0) — the route's pattern, never the actual URL (which may hold personal data). */
final class Where
{
    public static function surface(Request $request): string
    {
        $host = $request->getHost();
        foreach (['admin', 'app', 'my', 'help'] as $s) {
            if ($host === config("wurk.domains.{$s}")) {
                return $s;
            }
        }

        return $host === config('wurk.domains.root') ? 'marketing' : 'site';
    }

    public static function location(?Request $request): ?string
    {
        $route = $request?->route();
        if ($request === null || $route === null) {
            return null;
        }

        return mb_substr($request->method().' '.$route->uri().($route->getName() !== null ? ' ('.$route->getName().')' : ''), 0, 250);
    }
}
