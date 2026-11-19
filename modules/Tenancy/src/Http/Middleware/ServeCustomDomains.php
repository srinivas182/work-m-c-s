<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Tenancy\Contracts\CustomDomains;
use Wurk\Tenancy\Resolution\TenantResolver;

/**
 * Owners' own domains (Sprint 17.3), before routing:
 *
 * - A request for one of a business's own domains is served as its {handle}.{root} address — the same routes (site,
 *   bookings, shop, forms, chat) — with the domain the visitor used kept on the request (CustomDomains::REQUEST_HOST)
 *   for canonical links.
 * - When the business has made its own domain the main address, page visits to {handle}.{root} are sent there (301,
 *   path and query kept): one address for search engines. Never form posts, payment notices or signed links, which
 *   must stay exactly as they were issued.
 */
final readonly class ServeCustomDomains
{
    /** Paths that stay on the WURK address even after a main domain is chosen. */
    private const STAY = ['payments/', 'invoice/', 'webhooks/'];

    public function __construct(private TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $root = strtolower((string) config('wurk.domains.root'));
        $host = strtolower($request->getHost());

        if ($host !== $root && ! str_ends_with($host, '.'.$root)) {
            $tenant = $this->resolver->byHost($host);
            if ($tenant !== null) {
                $request->attributes->set(CustomDomains::REQUEST_HOST, $host);
                $request->headers->set('HOST', $tenant->handle().'.'.$root);
                $request->server->set('HTTP_HOST', $tenant->handle().'.'.$root);
            }

            return $next($request);
        }

        $label = substr($host, 0, -strlen('.'.$root));
        if ($label !== '' && ! str_contains($label, '.') && $request->isMethodCacheable() && ! $request->query->has('signature') && ! $this->stays($request)) {
            $tenant = $this->resolver->byHandle($label);
            $main = $tenant === null ? null : $this->resolver->primaryCustomHost($tenant->id);
            if ($main !== null) {
                return redirect()->away('https://'.$main.$request->getRequestUri(), 301);
            }
        }

        return $next($request);
    }

    private function stays(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        foreach (self::STAY as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
