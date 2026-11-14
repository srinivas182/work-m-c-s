<?php

declare(strict_types=1);

namespace Wurk\Data;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Data\Console\PurgeExportsCommand;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class DataServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [['label' => 'Your data', 'route' => 'tenant.data', 'icon' => 'database', 'section' => 'Settings', 'permission' => 'data.export', 'order' => 985]];
    }

    public function permissions(): array
    {
        return [['key' => 'data.export', 'label' => 'Download all of the business’s data, and restore recently deleted things']];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'data');
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExportsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('data:purge-exports')->hourlyAt(17)->onOneServer()->withoutOverlapping());
    }
}
