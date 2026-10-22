<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Billing\Support\BillingStatus;
use Wurk\Billing\Support\Dunning;
use Wurk\Tenancy\Contracts\TenantContext;

/** A business that hasn't paid (or whose trial has ended) can only reach Billing. */
final readonly class RequirePayment
{
    public function __construct(private TenantContext $context, private BillingStatus $status, private Dunning $dunning) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = (string) $request->route()?->getName();
        if ($this->context->has() && ! str_starts_with($route, 'tenant.billing') && $this->status->needsPayment($this->context->id())) {
            return redirect()->route('tenant.billing');
        }

        // Dunning grace (days 1–7): full service, with a banner on every page.
        if ($this->context->has() && $this->dunning->stage($this->context->id()) === 'grace') {
            // On the request, never Inertia::share(): shared state outlives the request in
            // long-running Octane workers and would leak this banner to other businesses.
            $request->attributes->set('wurk.banner', [
                'tone' => 'danger',
                'message' => 'We couldn’t take your last payment. Pay now to keep everything running.',
                'action' => ['label' => 'Pay now', 'href' => route('tenant.billing')],
            ]);
        }

        return $next($request);
    }
}
