<?php

declare(strict_types=1);

namespace Wurk\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Tenancy\Contracts\TenantContext;

/** Gives the back office's help panels a "Contact support" link (16.3). */
final readonly class ShareSupport
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->has() && $request->user() !== null) {
            // On the request, never Inertia::share() — shared state outlives the request in Octane workers.
            $request->attributes->set('wurk.support', ['url' => route('tenant.support.create')]);
        }

        return $next($request);
    }
}
