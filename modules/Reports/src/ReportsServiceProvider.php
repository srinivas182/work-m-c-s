<?php

declare(strict_types=1);

namespace Wurk\Reports;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Reports\Console\SendSummariesCommand;
use Wurk\Reports\Support\TakingsSection;

final class ReportsServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [
            ['label' => 'Overview', 'route' => 'tenant.reports', 'icon' => 'bar-chart', 'section' => 'Reports', 'permission' => 'reports.view', 'order' => 700],
            ['label' => 'VAT registration', 'route' => 'tenant.vat', 'icon' => 'receipt', 'section' => 'Settings', 'permission' => 'billing.manage', 'order' => 845],
        ];
    }

    public function permissions(): array
    {
        return [['key' => 'reports.view', 'label' => 'See the business’s reports (managers see their own branches)', 'scope' => 'business']];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'reports');
        if ($this->app->runningInConsole()) {
            $this->commands([SendSummariesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('reports:send-summaries')->hourly()->onOneServer()->withoutOverlapping()); // hourly: each business's own Monday/1st, once
        // Reports → Takings and VAT (Sprint 15.1b) — first, as it covers everything taken.
        $this->callAfterResolving(BusinessReports::class, function (BusinessReports $r): void {
            $r->register('takings', 50, fn (ReportPeriod $p) => ($p->forOwners || Gate::allows('reports.view'))
                ? app(TakingsSection::class)->build($p) : null);
        });
    }
}
