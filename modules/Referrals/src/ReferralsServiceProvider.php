<?php

declare(strict_types=1);

namespace Wurk\Referrals;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Wurk\Crm\Contracts\ContactRemembered;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Contracts\PurchaseRefunded;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\DashboardTiles;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Referrals\Console\ExpireWalletsCommand;
use Wurk\Referrals\Contracts\CustomerRewards;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Http\Controllers\PayoutsController;
use Wurk\Referrals\Http\Controllers\ReportsController;
use Wurk\Referrals\Support\Attribution;
use Wurk\Referrals\Support\Commissions;
use Wurk\Referrals\Support\PortalRewards;
use Wurk\Referrals\Support\Programme;
use Wurk\Referrals\Support\ReferralReports;
use Wurk\Referrals\Support\Wallets;
use Wurk\Tenancy\Contracts\TenantContext;

final class ReferralsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(ReferralRules::class, Programme::class);
        $this->app->bind(StoreCredit::class, Wallets::class); // the wallet is the business's store credit
        $this->app->bind(CustomerRewards::class, PortalRewards::class);
        $this->callAfterResolving(MessageTemplates::class, function (MessageTemplates $t): void { // Settings → Messages
            $t->define('referrals.payout_paid', PayoutsController::PAID);
            $t->define('referrals.payout_declined', PayoutsController::DECLINED);
        });
    }

    public function boot(): void
    {
        parent::boot();
        // Business home tiles (Sprint 13.9a): only for people who manage referrals, at businesses whose package has them.
        $this->callAfterResolving(DashboardTiles::class, function (DashboardTiles $tiles): void {
            $allowed = fn (): bool => app(TenantContext::class)->has() && Gate::allows('referrals.manage')
                && app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'referrals');
            $rand = fn (int $c): string => 'R'.number_format($c / 100, 2);
            $href = fn (): string => route('tenant.referral-reports', ['tenant' => app(TenantContext::class)->current()->handle()]);
            $tiles->register('referrals.sales', 300, fn () => $allowed() ? ['label' => 'Referral sales (this month)', 'href' => $href(),
                'value' => $rand(app(ReferralReports::class)->cost(...ReportsController::range('month', app(TenantContext::class)->current()->timezone()))['sales_cents'])] : null);
            $tiles->register('referrals.liability', 301, function () use ($allowed, $rand, $href) {
                if (! $allowed()) {
                    return null;
                }
                $l = app(ReferralReports::class)->liability();

                return ['label' => 'Wallet liability', 'value' => $rand($l['wallets_cents']), 'hint' => $rand($l['pending_cents']).' on its way', 'href' => $href()];
            });
        });
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireWalletsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('referrals:expire-wallets')->dailyAt('02:30')->onOneServer()->withoutOverlapping());
        // A customer remembered (booking, order, form, chat, sale): record who referred them, from the referral cookie.
        Event::listen(ContactRemembered::class, fn (ContactRemembered $e) => app(Attribution::class)->attach($e->contactId, app(Attribution::class)->codeFromRequest()));
        // A purchase confirmed (order, deposit, sale): rewards for the buyer and their referrers; a refund reverses them.
        Event::listen(PurchaseMade::class, fn (PurchaseMade $e) => app(Commissions::class)->purchase($e->sourceType, $e->sourceId, $e->paidCents, $e->commissionableCents, $e->branchId, $e->finalAt));
        Event::listen(PurchaseRefunded::class, fn (PurchaseRefunded $e) => app(Commissions::class)->refund($e->sourceType, $e->sourceId, $e->refundedCents));
    }

    public function menu(): array
    {
        return [
            ['label' => 'Referrals', 'route' => 'tenant.referrals', 'icon' => 'gift', 'section' => 'Customers', 'permission' => 'referrals.manage', 'entitlement' => 'referrals', 'order' => 330],
            ['label' => 'Payouts', 'route' => 'tenant.payouts', 'icon' => 'gift', 'section' => 'Customers', 'permission' => 'referrals.manage', 'entitlement' => 'referrals', 'order' => 331],
            ['label' => 'Referral reports', 'route' => 'tenant.referral-reports', 'icon' => 'gift', 'section' => 'Customers', 'permission' => 'referrals.manage', 'entitlement' => 'referrals', 'order' => 332],
            ['label' => 'Referrals', 'route' => 'tenant.referrals.settings', 'icon' => 'gift', 'section' => 'Settings', 'permission' => 'referrals.manage', 'entitlement' => 'referrals', 'order' => 838],
        ];
    }

    public function permissions(): array
    {
        return [['key' => 'referrals.manage', 'label' => 'Set the referral programme’s rules']];
    }
}
