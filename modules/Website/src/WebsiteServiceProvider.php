<?php

declare(strict_types=1);

namespace Wurk\Website;

use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Website\Console\InstallTemplatesCommand;
use Wurk\Website\Console\SeedCategoriesCommand;
use Wurk\Website\Console\SeedSampleTemplatesCommand;
use Wurk\Website\Contracts\SiteTemplates;
use Wurk\Website\Support\Branding;
use Wurk\Website\Support\EloquentSiteTemplates;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteAssets;
use Wurk\Website\Support\SiteBuilder;
use Wurk\Website\Support\Templates;
use Wurk\Website\Support\TemplateSwitcher;

final class WebsiteServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('config/website.php'), 'website');
        $this->app->singleton(Branding::class);
        $this->app->scoped(Pages::class);
        $this->app->scoped(SiteAssets::class);
        $this->app->singleton(Templates::class);
        $this->app->scoped(SiteBuilder::class);
        $this->app->scoped(TemplateSwitcher::class);
        $this->app->scoped(SiteTemplates::class, EloquentSiteTemplates::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'website.edit', 'label' => 'Edit website pages, menus and branding'],
            ['key' => 'website.publish', 'label' => 'Publish website changes'],
            ['key' => 'templates.manage', 'label' => 'Manage website categories and templates', 'scope' => 'platform'],
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
            ['label' => 'Templates', 'route' => 'admin.templates', 'icon' => 'layout-template', 'section' => 'Platform', 'permission' => 'templates.manage', 'order' => 60],
            ['label' => 'Write with AI', 'route' => 'tenant.website.ai', 'icon' => 'sparkles', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 102],
            ['label' => 'Media', 'route' => 'tenant.website.media', 'icon' => 'image', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 104],
            ['label' => 'Enquiries', 'route' => 'tenant.website.enquiries', 'icon' => 'inbox', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 103],
            ['label' => 'Menus', 'route' => 'tenant.website.menus', 'icon' => 'menu', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 101],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'website');
        if ($this->app->runningInConsole()) {
            $this->commands([SeedCategoriesCommand::class, SeedSampleTemplatesCommand::class, InstallTemplatesCommand::class]);
        }
    }
}
