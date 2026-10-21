<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Resolution;

use Illuminate\Contracts\Cache\Repository as Cache;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantDomain;

/**
 * Finds tenants by back-office handle or by public host. Lookups are cached
 * (misses briefly) and invalidated by model events. Only the tenant id is
 * cached, so status changes take effect immediately.
 */
final readonly class TenantResolver
{
    private const MISS = '__none__';

    public function __construct(private Cache $cache) {}

    public function byHandle(string $handle): ?Tenant
    {
        $handle = strtolower($handle);

        return $this->remember("tenancy:handle:{$handle}", fn () => Tenant::query()->where('handle', $handle)->value('id'));
    }

    public function byHost(string $host): ?Tenant
    {
        $host = strtolower($host);

        return $this->remember("tenancy:host:{$host}", fn () => TenantDomain::query()->where('host', $host)->value('tenant_id'));
    }

    public function forgetHandle(string $handle): void
    {
        $this->cache->forget('tenancy:handle:'.strtolower($handle));
    }

    public function forgetHost(string $host): void
    {
        $this->cache->forget('tenancy:host:'.strtolower($host));
    }

    /**
     * @param  callable(): mixed  $lookup
     */
    private function remember(string $key, callable $lookup): ?Tenant
    {
        $id = $this->cache->get($key);

        if (! is_string($id)) {
            $found = $lookup();
            $id = is_string($found) ? $found : self::MISS;
            $ttl = $id === self::MISS ? 60 : (int) config('wurk.tenancy.resolution_cache_ttl', 600);
            $this->cache->put($key, $id, $ttl);
        }

        return $id === self::MISS ? null : Tenant::query()->find($id);
    }
}
