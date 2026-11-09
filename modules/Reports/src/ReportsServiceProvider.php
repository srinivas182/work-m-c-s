<?php

declare(strict_types=1);

namespace Wurk\Reports;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class ReportsServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [['label' => 'Overview', 'route' => 'tenant.reports', 'icon' => 'bar-chart', 'section' => 'Reports', 'permission' => 'reports.view', 'order' => 700]];
    }

    public function permissions(): array
    {
        return [['key' => 'reports.view', 'label' => 'See the business’s reports (managers see their own branches)', 'scope' => 'business']];
    }
}
