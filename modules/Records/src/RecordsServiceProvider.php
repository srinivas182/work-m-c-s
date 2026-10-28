<?php

declare(strict_types=1);

namespace Wurk\Records;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Records\Console\FlagDueCommand;

final class RecordsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom($this->modulePath('config/records.php'), 'records');
    }

    public function permissions(): array
    {
        return [
            ['key' => 'records.view', 'label' => 'See and add patient documents'],
            ['key' => 'records.manage', 'label' => 'Remove patient records after the retention period'],
        ];
    }

    public function menu(): array
    {
        return [
            ['label' => 'Patient records', 'route' => 'tenant.records', 'icon' => 'file-lock', 'section' => 'Bookings', 'permission' => 'records.view', 'entitlement' => 'bookings', 'order' => 260],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'records');
        if ($this->app->runningInConsole()) {
            $this->commands([FlagDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('records:flag-due')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
        });
    }
}
