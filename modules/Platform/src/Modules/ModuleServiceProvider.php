<?php

declare(strict_types=1);

namespace Wurk\Platform\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base class every module's service provider extends.
 *
 * It wires up the module's migrations and routes by convention and gives each module
 * a uniform place to declare what it contributes to the platform. Declarations are
 * collected by the platform services that consume them as those services are built
 * (permissions in Sprint 1, settings in Sprint 2, blocks in Sprint 4, and so on).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    private ?string $modulePath = null;

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath('database/migrations'));
        $this->registerRoutes();
        $this->bootModule();
    }

    /**
     * Module-specific boot logic.
     */
    protected function bootModule(): void {}

    /**
     * Web routes get the "web" middleware group. API routes are versioned under /api/v1.
     */
    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $web = $this->modulePath('routes/web.php');

        if (is_file($web)) {
            Route::middleware('web')->group($web);
        }

        $api = $this->modulePath('routes/api.php');

        if (is_file($api)) {
            Route::middleware('api')->prefix('api/v1')->group($api);
        }
    }

    /**
     * Permissions this module defines. A string is a business permission; use an
     * array for a label or platform scope:
     *   'bookings.manage'
     *   ['key' => 'tenants.manage', 'label' => 'Manage businesses', 'scope' => 'platform']
     *
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Back-office navigation items. "icon" is a name from the frontend icon registry
     * (resources/js/design-system/icons.ts); "section" groups items in the sidebar.
     *
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, entitlement?: string, order?: int}>
     */
    public function menu(): array
    {
        return [];
    }

    /**
     * Settings screens this module contributes.
     *
     * @return list<array<string, mixed>>
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * Website builder blocks this module contributes.
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return [];
    }

    /**
     * Default email / SMS / WhatsApp templates keyed by event.
     *
     * @return list<array<string, mixed>>
     */
    public function messageTemplates(): array
    {
        return [];
    }

    /**
     * Feature flags this module declares, managed by WURK staff in the admin
     * (off, opt-in for businesses, percentage rollout, or on for everyone):
     *   'new-calendar' => ['label' => 'New booking calendar', 'description' => '…']
     *
     * @return array<string, array{label: string, description?: string}>
     */
    public function features(): array
    {
        return [];
    }

    /**
     * Reports this module contributes.
     *
     * @return list<array<string, mixed>>
     */
    public function reports(): array
    {
        return [];
    }

    /**
     * Absolute path inside this module's directory.
     */
    protected function modulePath(string $relative = ''): string
    {
        $this->modulePath ??= dirname((string) (new ReflectionClass(static::class))->getFileName(), 2);

        return rtrim($this->modulePath.'/'.ltrim($relative, '/'), '/');
    }
}
