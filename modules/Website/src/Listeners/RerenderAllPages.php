<?php

declare(strict_types=1);

namespace Wurk\Website\Listeners;

use Wurk\Platform\Events\BadgeChanged;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Jobs\RenderPublishedPage;
use Wurk\Website\Models\Page;

/** The "Verified by WURK" badge changed (25.4): every published page shows it in the footer, so all are re-rendered. */
final readonly class RerenderAllPages
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context) {}

    public function handle(BadgeChanged $event): void
    {
        $tenant = $this->tenants->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($tenant): void {
            foreach (Page::query()->whereNotNull('published_version_id')->pluck('published_version_id') as $version) {
                RenderPublishedPage::dispatch($tenant->tenantId(), (string) $version);
            }
        });
    }
}
