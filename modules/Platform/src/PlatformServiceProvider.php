<?php

declare(strict_types=1);

namespace Wurk\Platform;

use Wurk\Platform\Console\ListModulesCommand;
use Wurk\Platform\Console\MakeModuleCommand;
use Wurk\Platform\Modules\ModuleRegistry;
use Wurk\Platform\Modules\ModuleServiceProvider;

/**
 * Registered in bootstrap/providers.php. Boots the Platform module itself and then
 * registers every other installed module in dependency order.
 */
final class PlatformServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {

        $this->app->singleton(
            ModuleRegistry::class,
            fn (): ModuleRegistry => new ModuleRegistry($this->app->basePath('modules')),
        );

        $registry = $this->app->make(ModuleRegistry::class);

        foreach ($registry->all() as $manifest) {
            if ($manifest->key === 'platform') {
                continue;
            }

            $this->app->register($manifest->providerClass());
        }
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Design system', 'route' => 'platform.design', 'icon' => 'palette', 'section' => 'Platform', 'order' => 900],
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListModulesCommand::class,
                MakeModuleCommand::class,
            ]);
        }
    }
}
