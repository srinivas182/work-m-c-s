<?php

declare(strict_types=1);

namespace Wurk\Audit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id (trusting a well-formed X-Request-Id from the load
 * balancer), shared with logs, queued jobs and audit events, and returned to
 * the client so support can trace a report to exact log lines.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::ulid();

        Context::add('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
