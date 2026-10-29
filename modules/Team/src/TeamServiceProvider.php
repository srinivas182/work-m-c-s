<?php

declare(strict_types=1);

namespace Wurk\Team;

use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Team\Contracts\BranchLedger;
use Wurk\Team\Support\Settlements;

final class TeamServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(BranchLedger::class, Settlements::class);
    }

    public function menu(): array
    {
        return [
            ['label' => 'Team', 'route' => 'tenant.team', 'icon' => 'users', 'section' => 'Settings', 'permission' => 'team.view', 'order' => 800],
            ['label' => 'Branches', 'route' => 'tenant.branches', 'icon' => 'map-pin', 'section' => 'Settings', 'permission' => 'branches.view', 'order' => 805],
        ];
    }
}
