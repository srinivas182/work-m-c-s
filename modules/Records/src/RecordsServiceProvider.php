<?php

declare(strict_types=1);

namespace Wurk\Records;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Per business, link and visitor — never one counter per IP across every business (shared mobile IPs).
        $key = fn (Request $r, string $name) => $name.'|'.(string) $r->route('handle').'|'.substr(hash('sha256', (string) $r->route('token')), 0, 16).'|'.$r->ip();
        RateLimiter::for('records-read', fn (Request $r) => Limit::perMinute(60)->by($key($r, 'records-read')));
        RateLimiter::for('records-code', fn (Request $r) => Limit::perMinutes(10, 5)->by($key($r, 'records-code')));
        RateLimiter::for('records-verify', fn (Request $r) => Limit::perMinutes(10, 10)->by($key($r, 'records-verify')));
        if ($this->app->runningInConsole()) {
            $this->commands([FlagDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('records:flag-due')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
        });
    }
}
