<?php

declare(strict_types=1);

namespace Wurk\Payments;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\Coupons;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Payments\Contracts\PlatformPayments;
use Wurk\Payments\Contracts\Refunds;
use Wurk\Payments\Contracts\RefundWindow;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Support\InPersonLedger;
use Wurk\Payments\Support\NoCoupons;
use Wurk\Payments\Support\NoStoreCredit;
use Wurk\Payments\Support\Payments;
use Wurk\Payments\Support\RefundLedger;
use Wurk\Payments\Support\ReturnWindow;
use Wurk\Payments\Support\WurkPayments;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Money\Money;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(RefundWindow::class, ReturnWindow::class);
        $this->app->bindIf(StoreCredit::class, NoStoreCredit::class); // a module (Referrals) may provide it
        $this->app->bindIf(Coupons::class, NoCoupons::class); // the Campaigns module supplies the real coupon book (15.3b)
        $this->mergeConfigFrom($this->modulePath('config/payments.php'), 'payments');
        $this->app->bind(BusinessPayments::class, Payments::class);
        $this->app->bind(InPersonPayments::class, InPersonLedger::class);
        $this->app->bind(Refunds::class, RefundLedger::class); // 25.5
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
        // 26.2: money arriving online buzzes the phones of the people who handle the money.
        PaymentAttempt::saved(function (PaymentAttempt $a): void {
            if ($a->status === 'paid' && $a->wasChanged('status')) {
                app(PushNotifications::class)->notify('payment', 'Payment received: '.Money::format($a->amount_cents),
                    'Paid online ('.ucfirst($a->provider).') · '.$a->reference, route('tenant.money.owed'), 'money.manage', branchId: $a->branch_id);
            }
        });
        // Never one counter per IP across every business: provider notifications all come from the provider's
        // own IPs, so they're limited per business and provider; the hand-off page per business and visitor.
        RateLimiter::for('payments-handoff', fn (Request $r) => Limit::perMinute(60)->by('handoff|'.(string) ($r->route('handle') ?? 'wurk').'|'.$r->ip()));
        RateLimiter::for('payments-notify', fn (Request $r) => Limit::perMinute(600)->by('notify|'.(string) ($r->route('handle') ?? 'wurk').'|'.(string) $r->route('provider')));
    }
}
