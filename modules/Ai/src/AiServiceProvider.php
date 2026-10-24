<?php

declare(strict_types=1);

namespace Wurk\Ai;

use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiActions;
use Wurk\Ai\Support\AiClient;
use Wurk\Ai\Support\AiSettings;
use Wurk\Ai\Support\CostEstimates;
use Wurk\Ai\Support\CreditedActions;
use Wurk\Plans\Contracts\CreditCostEstimates;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class AiServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiSettings::class);
        $this->app->singleton(Ai::class, AiClient::class);
        $this->app->scoped(AiActions::class, CreditedActions::class);
        $this->app->singleton(CreditCostEstimates::class, CostEstimates::class);
    }

    /** @return list<array{key: string, label: string, scope?: string}> */
    public function permissions(): array
    {
        return [['key' => 'ai.manage', 'label' => 'Manage WURK AI (providers’ models, prices, margin, caps)', 'scope' => 'platform']];
    }

    /** @return list<array<string, mixed>> */
    public function menu(): array
    {
        return [['label' => 'WURK AI', 'route' => 'admin.ai', 'icon' => 'sparkles', 'section' => 'Settings', 'permission' => 'ai.manage', 'order' => 35]];
    }
}
