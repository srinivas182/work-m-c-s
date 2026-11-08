<?php

declare(strict_types=1);

namespace Wurk\Agents;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class AgentsServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [['label' => 'Agents', 'route' => 'admin.agents.index', 'icon' => 'users', 'section' => 'Platform', 'permission' => 'agents.view', 'order' => 40]];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'agents.view', 'label' => 'See agents and their teams', 'scope' => 'platform'],
            ['key' => 'agents.manage', 'label' => 'Create, move and suspend agents', 'scope' => 'platform'],
        ];
    }
}
