<?php

declare(strict_types=1);

namespace Wurk\Agents;

use Illuminate\Support\Facades\Event;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Events\TenantProvisioned;

final class AgentsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // A business created during signup is credited to the agent whose link the visitor followed (Sprint 14.2).
        Event::listen(TenantProvisioned::class, function (TenantProvisioned $e): void {
            if (app()->bound('request')) {
                app(AgentAttribution::class)->fromSignup($e->tenantId, request());
            }
        });
    }

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
