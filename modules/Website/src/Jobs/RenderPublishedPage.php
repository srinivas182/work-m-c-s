<?php

declare(strict_types=1);

namespace Wurk\Website\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\PageRender;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\PageRenderer;

/** Render a published page version to HTML; retried with back-off. The live site keeps its last version meanwhile. */
final class RenderPublishedPage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly string $tenantId, public readonly string $versionId) {}

    public function handle(TenantDirectory $tenants, TenantContext $context): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function (): void {
            $version = PageVersion::query()->find($this->versionId);
            if ($version === null) {
                return;
            }
            try {
                app(PageRenderer::class)->render($version);
            } catch (Throwable $e) {
                PageRender::query()->updateOrCreate(['page_version_id' => $version->id], ['error' => mb_substr($e->getMessage(), 0, 255)]);
                PageRender::query()->whereKey($version->id)->increment('attempts');
                throw $e;
            }
        });
    }
}
