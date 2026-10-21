<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Resolution\TenantResolver;

/**
 * Back office: app.{root}/{tenant}/… — enters the tenant named in the path.
 * Unknown and inactive businesses get a plain 404.
 */
final readonly class InitializeTenancyByPath
{
    public function __construct(private TenantResolver $resolver, private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $handle = $route instanceof Route ? $route->parameter('tenant') : null;
        $tenant = is_string($handle) ? $this->resolver->byHandle($handle) : null;

        abort_if($tenant === null || ! $tenant->isActive(), 404);

        $this->context->enter($tenant);

        if ($route instanceof Route) {
            $route->forgetParameter('tenant');
        }

        return $next($request);
    }

    public function terminate(): void
    {
        $this->context->forget();
    }
}
