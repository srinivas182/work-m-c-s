<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Context;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantContextMissing;
use Wurk\Tenancy\Events\TenantEntered;
use Wurk\Tenancy\Events\TenantLeft;
use Wurk\Tenancy\Sharding\ShardConnections;

final class TenantContextManager implements TenantContext
{
    public const CONTEXT_KEY = 'tenant_id';

    private ?CurrentTenant $tenant = null;

    private ?string $connection = null;

    public function __construct(
        private readonly ShardConnections $shards,
        private readonly Dispatcher $events,
        private readonly UrlGenerator $url,
    ) {}

    public function enter(CurrentTenant $tenant): void
    {
        if ($this->tenant?->tenantId() === $tenant->tenantId()) {
            return;
        }

        $this->forget();

        $this->connection = $this->shards->ensure($tenant->shardId());
        $this->tenant = $tenant;

        // Log lines and queued jobs carry the tenant automatically.
        // Resolved per call: the context repository is a scoped binding that is
        // replaced between requests and jobs, so it must never be captured.
        Context::add(self::CONTEXT_KEY, $tenant->tenantId());
        $this->url->defaults(['tenant' => $tenant->handle()]);

        $this->events->dispatch(new TenantEntered($tenant));
    }

    public function forget(): void
    {
        if ($this->tenant === null) {
            return;
        }

        $previous = $this->tenant;
        $this->tenant = null;
        $this->connection = null;

        Context::forget(self::CONTEXT_KEY);
        $this->url->defaults(['tenant' => null]);

        $this->events->dispatch(new TenantLeft($previous));
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function current(): CurrentTenant
    {
        return $this->tenant ?? throw TenantContextMissing::make();
    }

    public function id(): string
    {
        return $this->current()->tenantId();
    }

    public function connectionName(): string
    {
        return $this->connection ?? throw TenantContextMissing::make();
    }

    public function run(CurrentTenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->enter($tenant);

        try {
            return $callback($tenant);
        } finally {
            $previous !== null ? $this->enter($previous) : $this->forget();
        }
    }

    public function cacheKey(string $key): string
    {
        return 't:'.$this->id().':'.$key;
    }

    public function disk(): Filesystem
    {
        return Storage::build([
            'driver' => 'scoped',
            'disk' => config('filesystems.default'),
            'prefix' => 'tenants/'.$this->id(),
        ]);
    }
}
