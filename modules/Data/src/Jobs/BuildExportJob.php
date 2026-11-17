<?php

declare(strict_types=1);

namespace Wurk\Data\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Wurk\Data\Models\DataExport;
use Wurk\Data\Support\Exporter;
use Wurk\Platform\Queues;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Builds a full export in the background (Sprint 16.6). */
final class BuildExportJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $tenantId, public string $exportId)
    {
        $this->onQueue(Queues::BULK); // patient work (18.3a)
    }

    public function handle(TenantDirectory $tenants, TenantContext $context, Exporter $exporter): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function () use ($exporter): void {
            $export = DataExport::query()->whereKey($this->exportId)->where('status', 'queued')->first();
            if ($export !== null) {
                $exporter->build($export);
            }
        });
    }
}
