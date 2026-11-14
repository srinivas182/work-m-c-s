<?php

declare(strict_types=1);

namespace Wurk\Ai\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Ai\Support\Assistant;
use Wurk\Tenancy\Contracts\TenantContext;

/** Puts "Ask WURK" in a business's back-office header (16.2), unless WURK has switched it off. */
final readonly class ShareAssistant
{
    public function __construct(private TenantContext $context, private Assistant $assistant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->has() && $request->user() !== null && $this->assistant->settings()['enabled']) {
            // On the request, never Inertia::share() — shared state outlives the request in Octane workers.
            $request->attributes->set('wurk.assistant', ['url' => route('tenant.assistant')]);
        }

        return $next($request);
    }
}
