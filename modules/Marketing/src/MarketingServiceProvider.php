<?php

declare(strict_types=1);

namespace Wurk\Marketing;

use Wurk\Marketing\Sales\ContactWurkTool;
use Wurk\Platform\Contracts\SalesTools;
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
        // WURK's sales assistant (19.5) hands over to WURK through the contact form.
        $this->callAfterResolving(SalesTools::class, fn (SalesTools $t) => $t->register(ContactWurkTool::class));
    }
}
