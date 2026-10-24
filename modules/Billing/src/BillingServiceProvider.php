<?php

declare(strict_types=1);

namespace Wurk\Billing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Billing\Console\DunningCommand;
use Wurk\Billing\Console\RenewSubscriptionsCommand;
use Wurk\Billing\Contracts\ServiceStatus;
use Wurk\Billing\Events\PaymentFailed;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Billing\Gateways\DebitOrderProvider;
use Wurk\Billing\Gateways\FakeDebitOrderProvider;
use Wurk\Billing\Gateways\PaymentGateway;
use Wurk\Billing\Gateways\PaystackGateway;
use Wurk\Billing\Http\Middleware\RequirePayment;
use Wurk\Billing\Listeners\DunningNotices;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\DunningServiceStatus;
use Wurk\Billing\Support\Invoices;
use Wurk\Billing\Support\PackCreditPricing;
use Wurk\Plans\Contracts\CreditPricing;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreditPricing::class, PackCreditPricing::class);
        // First gateway (Sprint 3 decision 1); others implement the same contract.
        $this->app->singleton(PaymentGateway::class, PaystackGateway::class);
        // Until WURK chooses a DebiCheck provider (Sprint 3 decision 2); refuses to run in production.
        $this->app->singleton(DebitOrderProvider::class, FakeDebitOrderProvider::class);
        $this->app->singleton(ServiceStatus::class, DunningServiceStatus::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'billing.manage', 'label' => 'Manage billing, payments and credit purchases'],
            ['key' => 'payments.record-cash', 'label' => 'Record cash payments at a WURK office', 'scope' => 'platform'],
            ['key' => 'invoices.view', 'label' => 'See invoices, payments and failed payments', 'scope' => 'platform'],
            ['key' => 'invoices.manage', 'label' => 'Issue credit notes, retry payments, set invoice details and dunning', 'scope' => 'platform'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Billing', 'route' => 'tenant.billing', 'icon' => 'credit-card', 'section' => 'Settings', 'permission' => 'billing.manage', 'order' => 770],
            ['label' => 'Failed payments', 'route' => 'admin.billing.failed', 'icon' => 'triangle-alert', 'section' => 'Platform', 'permission' => 'invoices.view', 'order' => 35],
            ['label' => 'Invoice settings', 'route' => 'admin.billing.settings', 'icon' => 'receipt', 'section' => 'Settings', 'permission' => 'invoices.manage', 'order' => 936],
            ['label' => 'Credit packs', 'route' => 'admin.credits.packs', 'icon' => 'coins', 'section' => 'Settings', 'permission' => 'credits.manage', 'order' => 935],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'billing');

        // Separate counters per action: Laravel's default throttle key is per user only,
        // so wrong-code attempts would otherwise use up the "send a new code" allowance.
        RateLimiter::for('cash-approve', fn (Request $r) => Limit::perMinute(20)->by('cash-approve:'.$r->user()?->getAuthIdentifier().':'.$r->ip()));
        RateLimiter::for('cash-resend', fn (Request $r) => Limit::perMinute(5)->by('cash-resend:'.$r->user()?->getAuthIdentifier().':'.$r->ip()));
        // Any failed subscription payment (card now, debit order days later) makes an active subscription past due.
        Event::listen(PaymentFailed::class, DunningNotices::class);
        Event::listen(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $this->app->make(Invoices::class)->forPayment(Payment::query()->findOrFail($e->paymentId)));
        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', RequirePayment::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('billing:renew')->hourly()->onOneServer()->withoutOverlapping();
            $schedule->command('billing:dunning')->dailyAt('08:00')->timezone('Africa/Johannesburg')->onOneServer()->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RenewSubscriptionsCommand::class, DunningCommand::class]);
        }
    }
}
