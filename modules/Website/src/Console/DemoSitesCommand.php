<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

/**
 * One published demo site per repository template (demo-{template}), for the performance gate
 * in CI. Development and tests only — refused in production.
 */
final class DemoSitesCommand extends Command
{
    protected $signature = 'website:demo-sites';

    protected $description = 'Publish a demo site for every website template (development and tests only)';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        if (app()->environment('production')) {
            $this->error('Demo sites are for development and tests only.');

            return self::FAILURE;
        }
        foreach (glob(base_path('modules/Website/templates/*.json')) ?: [] as $file) {
            $key = (string) (json_decode((string) file_get_contents($file), true)['key'] ?? '');
            $handle = 'demo-'.$key;
            if ($tenants->findByHandle($handle) === null) {
                Artisan::call('tenants:create', ['name' => 'Demo '.$key, '--handle' => $handle, '--no-interaction' => true]);
            }
            $tenant = $tenants->findByHandle($handle) ?? throw new \RuntimeException("Could not create {$handle}");
            $published = $context->run($tenant, fn () => Page::query()->where('is_home', true)->whereNotNull('published_version_id')->exists());
            if ($published) {
                $this->line("{$handle}: already published");

                continue;
            }
            $context->run($tenant, function () use ($key): void {
                app(SiteBuilder::class)->apply($key);
                foreach (Page::query()->get() as $page) {
                    app(Pages::class)->publish($page, 'Demo', null);
                }
            });
            $this->line("{$handle}: published");
        }

        return self::SUCCESS;
    }
}
