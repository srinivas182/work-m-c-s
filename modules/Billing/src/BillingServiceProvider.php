<?php

declare(strict_types=1);

namespace Wurk\Billing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Billing\Console\DunningCommand;
use Wurk\Billing\Console\RenewSubscriptionsCommand;
use Wurk\Billing\Console\UsageSummariesCommand;
use Wurk\Billing\Contracts\ServiceStatus;
use Wurk\Billing\Contracts\SplitPayments;
use Wurk\Billing\Contracts\SubscriptionPayments;
use Wurk\Billing\Contracts\WurkRefundWindow;
use Wurk\Billing\Events\PaymentFailed;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Billing\Gateways\DebitOrderProvider;
use Wurk\Billing\Gateways\FakeDebitOrderProvider;
use Wurk\Billing\Gateways\PaymentGateway;
use Wurk\Billing\Gateways\PaystackGateway;
use Wurk\Billing\Gateways\PaystackSplitPayments;
use Wurk\Billing\Http\Middleware\RequirePayment;
use Wurk\Billing\Jobs\AutoTopUpJob;
use Wurk\Billing\Listeners\DunningNotices;
use Wurk\Billing\Metrics\HealthSection;
use Wurk\Billing\Metrics\ProfitSection;
use Wurk\Billing\Metrics\RevenueSection;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\BillingSettings;
use Wurk\Billing\Support\DunningServiceStatus;
use Wurk\Billing\Support\Invoices;
use Wurk\Billing\Support\PackCreditPricing;
use Wurk\Billing\Support\PaidSubscriptions;
use Wurk\Billing\Support\Payments;
use Wurk\Credits\Events\LowCreditBalance;
use Wurk\Payments\Contracts\PlatformPaymentReceived;
use Wurk\Plans\Contracts\CreditPricing;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;

final class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionPayments::class, PaidSubscriptions::class);
        $this->app->bind(WurkRefundWindow::class, BillingSettings::class);
        $this->app->singleton(CreditPricing::class, PackCreditPricing::class);
        $this->app->singleton(SplitPayments::class, PaystackSplitPayments::class);
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
            ['key' => 'costs.manage', 'label' => 'Set what things cost WURK (profitability)', 'scope' => 'platform'],
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
            ['label' => 'Profitability', 'route' => 'admin.profitability', 'icon' => 'bar-chart', 'section' => 'Platform', 'permission' => 'metrics.view', 'order' => 16],
            ['label' => 'Reconciliation', 'route' => 'admin.reconciliation', 'icon' => 'receipt', 'section' => 'Platform', 'permission' => 'costs.manage', 'order' => 17],
            ['label' => 'Failed payments', 'route' => 'admin.billing.failed', 'icon' => 'triangle-alert', 'section' => 'Platform', 'permission' => 'invoices.view', 'order' => 35],
            ['label' => 'Invoice settings', 'route' => 'admin.billing.settings', 'icon' => 'receipt', 'section' => 'Settings', 'permission' => 'invoices.manage', 'order' => 936],
            ['label' => 'Credit packs', 'route' => 'admin.credits.packs', 'icon' => 'coins', 'section' => 'Settings', 'permission' => 'credits.manage', 'order' => 935],
        ];
    }

    protected function bootModule(): void
    {
        $this->callAfterResolving(Schedule::class, fn (Schedule $sch) => $sch->command('billing:usage-summaries')->hourly()->onOneServer()->withoutOverlapping()); // 15.5b
        // Auto top-up (Sprint 15.5b): queued after the work that ran credits low, so a card charge never slows a send.
        Event::listen(LowCreditBalance::class, fn (LowCreditBalance $e) => AutoTopUpJob::dispatch($e->tenantId)->afterCommit());
        // Admin → Metrics → At risk (Sprint 15.6).
        $this->callAfterResolving(PlatformMetrics::class, function (PlatformMetrics $m): void {
            $m->register('health', 120, fn (ReportPeriod $p) => Gate::allows('metrics.view') ? app(HealthSection::class)->build($p) : null);
        });
        // Admin → Metrics → Profit (Sprint 15.5a).
        $this->callAfterResolving(PlatformMetrics::class, function (PlatformMetrics $m): void {
            $m->register('profit', 70, fn (ReportPeriod $p) => Gate::allows('metrics.view') ? app(ProfitSection::class)->build($p) : null);
        });
        // Admin → Metrics → Revenue (Sprint 15.4b).
        $this->callAfterResolving(PlatformMetrics::class, function (PlatformMetrics $m): void {
            $m->register('revenue', 50, fn (ReportPeriod $p) => Gate::allows('metrics.view') ? app(RevenueSection::class)->build($p) : null);
        });
        Event::listen(PlatformPaymentReceived::class, fn (PlatformPaymentReceived $e) => Payment::query()->where('reference', $e->reference)->exists() ? $this->app->make(Payments::class)->confirm($e->reference) : null);
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
            $this->commands([UsageSummariesCommand::class, RenewSubscriptionsCommand::class, DunningCommand::class]);
        }
    }
}
