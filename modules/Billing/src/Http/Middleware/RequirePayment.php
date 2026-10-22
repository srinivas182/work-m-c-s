<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Billing\Support\BillingStatus;
use Wurk\Tenancy\Contracts\TenantContext;

/** A business that hasn't paid (or whose trial has ended) can only reach Billing. */
final readonly class RequirePayment
{
    public function __construct(private TenantContext $context, private BillingStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = (string) $request->route()?->getName();
        if ($this->context->has() && ! str_starts_with($route, 'tenant.billing') && $this->status->needsPayment($this->context->id())) {
            return redirect()->route('tenant.billing');
        }

        return $next($request);
    }
}
