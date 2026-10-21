<?php

declare(strict_types=1);

namespace Wurk\Plans\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Support\ModuleCatalogue;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Route middleware "entitled:{module}" for business routes. A business without
 * the module gets a friendly "not in your package" page instead of the feature.
 */
final readonly class EnsureEntitled
{
    public function __construct(private Entitlements $entitlements, private TenantContext $context, private ModuleCatalogue $catalogue) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if ($this->context->has() && ! $this->entitlements->entitled($this->context->id(), $module)) {
            return Inertia::render('Plans::NotIncluded', [
                'module' => $this->catalogue->all()[$module]['name'] ?? $module,
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
