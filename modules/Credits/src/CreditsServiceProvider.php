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
use Wurk\Credits\Support\EloquentRateCard;
use Wurk\Credits\Support\RolledUpUsage;
use Wurk\Credits\Support\Wallet;
use Wurk\Plans\Events\SubscriptionChanged;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;

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
