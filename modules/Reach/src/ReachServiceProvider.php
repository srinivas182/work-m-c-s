<?php

declare(strict_types=1);

namespace Wurk\Reach;

use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Reach\Support\ReachNumbers;

/**
 * Reach (Sprint 25.2): customers book from any phone, without data — by USSD (one WURK code, a short number per
 * business) and by SMS. The menu uses the AI receptionist's booking tools, so the rules are the website's.
 */
final class ReachServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReachNumbers::class);
    }
}
