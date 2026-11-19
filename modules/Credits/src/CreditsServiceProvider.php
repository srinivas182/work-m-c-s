<?php

declare(strict_types=1);

namespace Wurk\Credits;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Console\GrantAllowancesCommand;
use Wurk\Credits\Console\RollupUsageCommand;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\RateCard;
use Wurk\Credits\Contracts\UsageHistory;
use Wurk\Credits\Events\LowCreditBalance;
use Wurk\Credits\Listeners\GrantFirstAllowance;
use Wurk\Credits\Metrics\UsageSection;
use Wurk\Credits\Support\CreditsSignal;
use Wurk\Credits\Support\EloquentRateCard;
use Wurk\Credits\Support\RolledUpUsage;
use Wurk\Credits\Support\Wallet;
use Wurk\Plans\Events\SubscriptionChanged;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Platform\Contracts\NeedsYouNow;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

final class CreditsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UsageHistory::class, RolledUpUsage::class);
        $this->app->singleton(RateCard::class, EloquentRateCard::class);
        $this->app->singleton(CreditWallet::class, Wallet::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'credits.view', 'label' => 'See businesses’ WURK Credits', 'scope' => 'platform'],
            ['key' => 'credits.manage', 'label' => 'Set credit rates and adjust balances', 'scope' => 'platform'],
            ['key' => 'credits.view-own', 'label' => 'See WURK Credits and usage'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Credit rates', 'route' => 'admin.credits.rates', 'icon' => 'coins', 'section' => 'Settings', 'permission' => 'credits.manage', 'order' => 930],
            ['label' => 'Plan & usage', 'route' => 'tenant.credits', 'icon' => 'coins', 'section' => 'Settings', 'permission' => 'credits.view-own', 'order' => 780],
        ];
    }

    protected function bootModule(): void
    {
        $this->callAfterResolving(HealthSignals::class, fn (HealthSignals $s) => $s->register('credits', fn (string $t) => $this->app->make(CreditsSignal::class)($t))); // business health (16.5)
        // Admin → Metrics → Credits used (Sprint 15.4b), from a nightly roll-up of each business's ledger.
        if ($this->app->runningInConsole()) {
            $this->commands([RollupUsageCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('credits:rollup-usage')->dailyAt('01:30')->onOneServer()->withoutOverlapping());
        $this->callAfterResolving(PlatformMetrics::class, function (PlatformMetrics $m): void {
            $m->register('credits', 400, fn (ReportPeriod $p) => Gate::allows('metrics.view') ? app(UsageSection::class)->build($p) : null);
        });
        Event::listen(SubscriptionChanged::class, GrantFirstAllowance::class);
        // Notification delivery to owners arrives with the notification engine; for now the alert is recorded.
        // Home's "Needs you now" (26.3): credits running out.
        $this->callAfterResolving(NeedsYouNow::class, fn (NeedsYouNow $n) => $n->register('credits', function (): array {
            if (! Gate::allows('credits.view-own')) {
                return [];
            }
            $wallet = app(CreditWallet::class);
            $b = $wallet->balance();
            // Only while they're using credits (as the health signal, 16.5): a business that sends nothing isn't nagged.
            if ($b['low_balance_milli'] <= 0 || $b['balance_milli'] >= $b['low_balance_milli'] || array_sum(array_column($wallet->usageThisMonth(), 'milli')) <= 0) {
                return [];
            }

            return [['id' => 'credits', 'kind' => __('Credits'), 'title' => __('Credits running out'), 'detail' => __(':n credits left — messages and WURK AI stop when they run out.',
                ['n' => rtrim(rtrim(number_format($b['balance_milli'] / 1000, 1, '.', ''), '0'), '.')]), 'at' => now()->toIso8601String(), 'url' => route('tenant.credits'),
                'actions' => [['label' => __('Top up'), 'url' => route('tenant.credits'), 'data' => [], 'tone' => 'primary', 'link' => true]]]];
        }));
        // 26.2: credits running low buzz the phones of the people who see the business's credits.
        Event::listen(LowCreditBalance::class, fn (LowCreditBalance $e) => $this->app->make(TenantContext::class)->has() && $this->app->make(TenantContext::class)->id() === $e->tenantId
            ? $this->app->make(PushNotifications::class)->notify('credits', 'Credits running low', 'You have '.rtrim(rtrim(number_format($e->balanceMilli / 1000, 1, '.', ''), '0'), '.')
                .' credits left. Top up so messages and WURK AI keep working.', route('tenant.credits'), 'credits.view-own') : null);
        Event::listen(LowCreditBalance::class, fn (LowCreditBalance $e) => $this->app->make(AuditLog::class)->record(
            'credits.low_balance', 'tenant', $e->tenantId, ['balance' => $e->balanceMilli / 1000, 'threshold' => $e->thresholdMilli / 1000],
        ));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('credits:grant-allowances')->dailyAt('01:00')->timezone('Africa/Johannesburg')->onOneServer()->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([GrantAllowancesCommand::class]);
        }
    }
}
