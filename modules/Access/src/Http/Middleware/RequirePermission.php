<?php

declare(strict_types=1);

namespace Wurk\Access\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Route middleware "permission:{key}". Inside a business a refusal is a 404,
 * so people outside the team cannot tell what exists; elsewhere it is a 403.
 */
final readonly class RequirePermission
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! Gate::forUser($request->user())->allows($permission)) {
            abort($this->context->has() ? 404 : 403);
        }

        return $next($request);
    }
}
