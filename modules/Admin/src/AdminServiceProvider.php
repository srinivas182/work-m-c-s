<?php

declare(strict_types=1);

namespace Wurk\Admin;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class AdminServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'admin.home', 'icon' => 'layout-dashboard', 'section' => 'Platform', 'order' => 10],
        ];
    }
}
