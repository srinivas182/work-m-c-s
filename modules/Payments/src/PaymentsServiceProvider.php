<?php

declare(strict_types=1);

namespace Wurk\Payments;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\PlatformPayments;
use Wurk\Payments\Support\Payments;
use Wurk\Payments\Support\WurkPayments;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom($this->modulePath('config/payments.php'), 'payments');
        $this->app->bind(BusinessPayments::class, Payments::class);
        $this->app->bind(PlatformPayments::class, WurkPayments::class);
    }

    public function permissions(): array
    {
        return [
            ['key' => 'payments.manage', 'label' => 'Connect payment methods (PayFast, Yoco, Ozow, Peach, EFT)'],
        ];
    }

    public function menu(): array
    {
        return [
            ['label' => 'Payment methods', 'route' => 'admin.payment-methods', 'icon' => 'credit-card', 'section' => 'Settings', 'permission' => 'providers.manage', 'order' => 34],
            ['label' => 'Payments', 'route' => 'tenant.payments.settings', 'icon' => 'credit-card', 'section' => 'Settings', 'permission' => 'payments.manage', 'order' => 820],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'payments');
        // Never one counter per IP across every business: provider notifications all come from the provider's
        // own IPs, so they're limited per business and provider; the hand-off page per business and visitor.
        RateLimiter::for('payments-handoff', fn (Request $r) => Limit::perMinute(60)->by('handoff|'.(string) ($r->route('handle') ?? 'wurk').'|'.$r->ip()));
        RateLimiter::for('payments-notify', fn (Request $r) => Limit::perMinute(600)->by('notify|'.(string) ($r->route('handle') ?? 'wurk').'|'.(string) $r->route('provider')));
    }
}
