<?php

declare(strict_types=1);

namespace Wurk\Website\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Support\ProductPages;

/** Re-render a business's product pages (one job at a time per business: rapid edits coalesce). */
final class RenderProductPages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public int $uniqueFor = 60;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantDirectory $tenants, TenantContext $context): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant !== null) {
            $context->run($tenant, fn () => app(ProductPages::class)->renderAll());
        }
    }
}
