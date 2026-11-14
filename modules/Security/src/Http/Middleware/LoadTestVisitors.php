<?php

declare(strict_types=1);

namespace Wurk\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Load tests on staging (Sprint 18.3b): a few test machines play thousands of visitors. With the secret
 * `WURK_LOAD_TEST_TOKEN` in `X-Wurk-Load-Test`, the address in `X-Wurk-Load-Visitor` is used as the visitor's IP, so
 * per-visitor rate limits behave as they would for real, separate people. Never active in production, nor without
 * the token.
 */
final class LoadTestVisitors
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('wurk.load_test.token', '');
        $visitor = (string) $request->headers->get('X-Wurk-Load-Visitor', '');
        if ($token !== '' && ! app()->isProduction() && hash_equals($token, (string) $request->headers->get('X-Wurk-Load-Test', ''))
            && filter_var($visitor, FILTER_VALIDATE_IP) !== false) {
            $request->server->set('REMOTE_ADDR', $visitor);
            $request->headers->set('X-Forwarded-For', $visitor);
        }

        return $next($request);
    }
}
