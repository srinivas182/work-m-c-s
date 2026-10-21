<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Resolution\TenantResolver;

/**
 * Public business websites: {handle}.{root} or a custom domain.
 */
final readonly class InitializeTenancyByDomain
{
    public function __construct(private TenantResolver $resolver, private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->byHost($request->getHost());

        abort_if($tenant === null || ! $tenant->isActive(), 404);

        $this->context->enter($tenant);

        return $next($request);
    }

    public function terminate(): void
    {
        $this->context->forget();
    }
}
