<?php

declare(strict_types=1);

namespace Wurk\Onboarding;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class OnboardingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('config/disposable-domains.php'), 'onboarding.disposable-domains');
    }

    protected function bootModule(): void
    {
        // Generous for real people, tight for scripts. Code sending has its own per-address limits.
        RateLimiter::for('signup', fn (Request $request) => Limit::perMinute(30)->by('signup:'.$request->ip()));
        // Starting (or restarting) a signup sends a code, so it is capped per hour.
        RateLimiter::for('signup-start', fn (Request $request) => Limit::perHour(10)->by('signup-start:'.$request->ip()));
    }
}
