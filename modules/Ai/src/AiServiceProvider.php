<?php

declare(strict_types=1);

namespace Wurk\Ai;

use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Support\AiActions;
use Wurk\Ai\Support\AiClient;
use Wurk\Ai\Support\AiSettings;
use Wurk\Ai\Support\CostEstimates;
use Wurk\Plans\Contracts\CreditCostEstimates;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class AiServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiSettings::class);
        $this->app->singleton(Ai::class, AiClient::class);
        $this->app->scoped(AiActions::class);
        $this->app->singleton(CreditCostEstimates::class, CostEstimates::class);
    }
}
