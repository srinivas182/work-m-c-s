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
        // Counted per person (their browser session), with a much higher ceiling per IP:
        // South African mobile networks put many customers behind shared IP addresses,
        // so an IP-only limit would let strangers throttle each other.
        RateLimiter::for('signup', fn (Request $request) => [
            Limit::perMinute(30)->by('signup:session:'.$request->session()->getId()),
            Limit::perMinute(300)->by('signup:ip:'.$request->ip()),
        ]);
        // Starting (or restarting) a signup sends a paid code, so it is capped per hour —
        // per session, with a per-IP backstop against scripted abuse.
        RateLimiter::for('signup-start', fn (Request $request) => [
            Limit::perHour(5)->by('signup-start:session:'.$request->session()->getId()),
            Limit::perHour(60)->by('signup-start:ip:'.$request->ip()),
        ]);
    }
}
