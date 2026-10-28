<?php

declare(strict_types=1);

namespace Wurk\Team;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class TeamServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [
            ['label' => 'Branches', 'route' => 'tenant.branches', 'icon' => 'map-pin', 'section' => 'Settings', 'permission' => 'branches.view', 'order' => 805],
        ];
    }
}
