<?php

declare(strict_types=1);

namespace Wurk\Marketing;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class MarketingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'marketing');
    }
}
