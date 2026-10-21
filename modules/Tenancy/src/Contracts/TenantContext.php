<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Which business the current request, job or command is running for.
 *
 * Entering a tenant points tenant models at the tenant's shard and adds the tenant
 * to log context and queued-job context. Leaving reverses it. Under Octane the
 * context is reset at the start and end of every request.
 */
interface TenantContext
{
    public function enter(CurrentTenant $tenant): void;

    public function forget(): void;

    public function has(): bool;

    /** @throws TenantContextMissing */
    public function current(): CurrentTenant;

    /** @throws TenantContextMissing */
    public function id(): string;

    /** Database connection name for the current tenant's shard. @throws TenantContextMissing */
    public function connectionName(): string;

    /**
     * Run a callback inside a tenant, restoring whatever context was active before.
     *
     * @template T
     *
     * @param  Closure(CurrentTenant): T  $callback
     * @return T
     */
    public function run(CurrentTenant $tenant, Closure $callback): mixed;

    /** Cache key namespaced to the current tenant. */
    public function cacheKey(string $key): string;

    /** Filesystem scoped to tenants/{id}/ on the default disk. */
    public function disk(): Filesystem;
}
