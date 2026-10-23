<?php

declare(strict_types=1);

namespace Wurk\Website;

use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Website\Support\Branding;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteAssets;

final class WebsiteServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('config/website.php'), 'website');
        $this->app->singleton(Branding::class);
        $this->app->scoped(Pages::class);
        $this->app->scoped(SiteAssets::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'website.edit', 'label' => 'Edit website pages, menus and branding'],
            ['key' => 'website.publish', 'label' => 'Publish website changes'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, entitlement?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Pages', 'route' => 'tenant.website.pages', 'icon' => 'globe', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 100],
            ['label' => 'Branding', 'route' => 'tenant.website.branding', 'icon' => 'palette', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 102],
            ['label' => 'Menus', 'route' => 'tenant.website.menus', 'icon' => 'menu', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 101],
        ];
    }
}
