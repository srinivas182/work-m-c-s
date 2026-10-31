<?php

declare(strict_types=1);

namespace Wurk\Website;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\ContactsBackfillRequested;
use Wurk\Plans\Contracts\UsageMeter;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Website\Console\DemoSitesCommand;
use Wurk\Website\Console\ExpireFormFilesCommand;
use Wurk\Website\Console\InstallTemplatesCommand;
use Wurk\Website\Console\RerenderDatedPagesCommand;
use Wurk\Website\Console\SeedCategoriesCommand;
use Wurk\Website\Console\SeedSampleTemplatesCommand;
use Wurk\Website\Contracts\CatalogChanged;
use Wurk\Website\Contracts\MediaLibrary;
use Wurk\Website\Contracts\ProductCatalog;
use Wurk\Website\Contracts\SiteTemplates;
use Wurk\Website\Listeners\RerenderShopPages;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Support\Branding;
use Wurk\Website\Support\EloquentSiteTemplates;
use Wurk\Website\Support\NoProductCatalog;
use Wurk\Website\Support\PageRenderer;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteAssets;
use Wurk\Website\Support\SiteBuilder;
use Wurk\Website\Support\SiteMediaLibrary;
use Wurk\Website\Support\StorageMeter;
use Wurk\Website\Support\Templates;
use Wurk\Website\Support\TemplateSwitcher;
use Wurk\Website\Support\Uploads\ClamdScanner;
use Wurk\Website\Support\Uploads\VirusScanner;

final class WebsiteServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VirusScanner::class, ClamdScanner::class);
        $this->app->tag([StorageMeter::class], UsageMeter::TAG);
        $this->mergeConfigFrom($this->modulePath('config/website.php'), 'website');
        $this->app->singleton(Branding::class);
        $this->app->scoped(Pages::class);
        $this->app->scoped(SiteAssets::class);
        $this->app->singleton(Templates::class);
        $this->app->scoped(SiteBuilder::class);
        $this->app->scoped(TemplateSwitcher::class);
        $this->app->scoped(PageRenderer::class);
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
            ['label' => 'Time to launch', 'route' => 'admin.launch-times', 'icon' => 'clock', 'section' => 'Platform', 'permission' => 'tenants.view', 'order' => 61],
            ['label' => 'Templates', 'route' => 'admin.templates', 'icon' => 'layout-template', 'section' => 'Platform', 'permission' => 'templates.manage', 'order' => 60],
            ['label' => 'Write with AI', 'route' => 'tenant.website.ai', 'icon' => 'sparkles', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 102],
            ['label' => 'Media', 'route' => 'tenant.website.media', 'icon' => 'image', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 104],
            ['label' => 'Enquiries', 'route' => 'tenant.website.enquiries', 'icon' => 'inbox', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 103],
            ['label' => 'Menus', 'route' => 'tenant.website.menus', 'icon' => 'menu', 'section' => 'Website', 'permission' => 'website.edit', 'entitlement' => 'website', 'order' => 101],
        ];
    }

    protected function bootModule(): void
    {
        $this->app->bindIf(ProductCatalog::class, NoProductCatalog::class); // Shop provides the real one
        $this->app->bind(MediaLibrary::class, SiteMediaLibrary::class);
        $this->app->bind(MediaLibrary::class, SiteMediaLibrary::class);
        Event::listen(CatalogChanged::class, RerenderShopPages::class);
        Event::listen(ContactsBackfillRequested::class, function (): void { // past enquiries → contacts (the 12.1 release step)
            $book = app(ContactBook::class);
            Enquiry::query()->orderBy('id')->chunk(500, function ($rows) use ($book): void {
                foreach ($rows as $e) {
                    $contact = $book->remember($e->name, $e->phone, $e->email, 'form', $e->id, null, $e->service !== null ? 'Quote request: '.$e->service : 'Website enquiry');
                    if ($e->contact_id === null) {
                        $e->update(['contact_id' => $contact]);
                    }
                }
            });
        });
        // Quote forms: per business AND visitor (see the Bookings limiters for why).
        // The chatbot: per business AND visitor (never one counter across businesses).
        RateLimiter::for('chat', fn (Request $r) => Limit::perMinute(60)->by('chat|'.(string) $r->route('handle').'|'.$r->ip()));
        RateLimiter::for('chat-handoff', fn (Request $r) => Limit::perMinutes(10, 5)->by('chat-handoff|'.(string) $r->route('handle').'|'.$r->ip()));
        RateLimiter::for('quote-form', fn (Request $r) => Limit::perMinutes(10, 10)->by('quote-form|'.(string) $r->route('handle').'|'.$r->ip()));
        // Dated content (promotions, expiring specials) refreshed just after midnight.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('website:rerender-dated')->dailyAt('00:05')->timezone('Africa/Johannesburg')->onOneServer()->withoutOverlapping();
            $schedule->command('website:expire-form-files')->dailyAt('03:15')->onOneServer()->withoutOverlapping();
        });
        $this->loadViewsFrom($this->modulePath('resources/views'), 'website');
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireFormFilesCommand::class, SeedCategoriesCommand::class, SeedSampleTemplatesCommand::class, InstallTemplatesCommand::class, RerenderDatedPagesCommand::class, DemoSitesCommand::class]);
        }
    }
}
