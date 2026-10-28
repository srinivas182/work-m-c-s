<?php

declare(strict_types=1);

namespace Wurk\Team;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class TeamServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [
            ['label' => 'Team', 'route' => 'tenant.team', 'icon' => 'users', 'section' => 'Settings', 'permission' => 'team.view', 'order' => 800],
            ['label' => 'Branches', 'route' => 'tenant.branches', 'icon' => 'map-pin', 'section' => 'Settings', 'permission' => 'branches.view', 'order' => 805],
        ];
    }
}
