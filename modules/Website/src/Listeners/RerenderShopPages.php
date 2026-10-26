<?php

declare(strict_types=1);

namespace Wurk\Website\Listeners;

use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Contracts\CatalogChanged;
use Wurk\Website\Jobs\RenderPublishedPage;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;

/** Products changed: re-render the business's published pages that contain shop sections. */
final readonly class RerenderShopPages
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context) {}

    public function handle(CatalogChanged $event): void
    {
        $tenant = $this->tenants->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($tenant): void {
            foreach (Page::query()->whereNotNull('published_version_id')->get(['id', 'published_version_id']) as $page) {
                $content = (string) PageVersion::query()->whereKey($page->published_version_id)->value('content');
                if (str_contains($content, '"type":"ProductGrid"')) {
                    RenderPublishedPage::dispatch($tenant->tenantId(), (string) $page->published_version_id);
                }
            }
        });
    }
}
