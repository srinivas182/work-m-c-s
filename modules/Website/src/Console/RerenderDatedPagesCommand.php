<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Jobs\RenderPublishedPage;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\SiteLaunch;

/**
 * Published pages are static HTML, so content that depends on the date (a promotion that
 * starts or ends, specials that expire) is re-rendered just after midnight each day. Any
 * published page containing a date is included — so future dated sections are covered too.
 */
final class RerenderDatedPagesCommand extends Command
{
    protected $signature = 'website:rerender-dated';

    protected $description = 'Re-render published pages whose content depends on the date';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $queued = 0;
        foreach (SiteLaunch::query()->pluck('tenant_id') as $tenantId) {
            $tenant = $tenants->find((string) $tenantId);
            if ($tenant === null) {
                continue;
            }
            $queued += (int) $context->run($tenant, function () use ($tenant): int {
                $n = 0;
                foreach (Page::query()->whereNotNull('published_version_id')->get(['id', 'published_version_id']) as $page) {
                    $content = (string) PageVersion::query()->whereKey($page->published_version_id)->value('content');
                    if (preg_match('/"\d{4}-\d{2}-\d{2}"/', $content) === 1) {
                        RenderPublishedPage::dispatch($tenant->tenantId(), (string) $page->published_version_id);
                        $n++;
                    }
                }

                return $n;
            });
        }
        $this->info("{$queued} dated page(s) queued for re-rendering.");

        return self::SUCCESS;
    }
}
