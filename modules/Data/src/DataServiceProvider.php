<?php

declare(strict_types=1);

namespace Wurk\Data;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Data\Console\PurgeDeletedCommand;
use Wurk\Data\Console\PurgeExportsCommand;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class DataServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [
            ['label' => 'Your data', 'route' => 'tenant.data', 'icon' => 'database', 'section' => 'Settings', 'permission' => 'data.export', 'order' => 985],
            ['label' => 'Recently deleted', 'route' => 'tenant.data.deleted', 'icon' => 'trash', 'section' => 'Settings', 'permission' => 'data.export', 'order' => 986],
        ];
    }

    public function permissions(): array
    {
        return [['key' => 'data.export', 'label' => 'Download all of the business’s data, and restore recently deleted things']];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'data');
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExportsCommand::class, PurgeDeletedCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('data:purge-exports')->hourlyAt(17)->onOneServer()->withoutOverlapping();
            $s->command('data:purge-deleted')->dailyAt('03:23')->onOneServer()->withoutOverlapping();
        });
    }
}
