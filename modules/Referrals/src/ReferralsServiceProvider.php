<?php

declare(strict_types=1);

namespace Wurk\Referrals;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Wurk\Crm\Contracts\ContactRemembered;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Contracts\PurchaseRefunded;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Referrals\Console\ExpireWalletsCommand;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Support\Attribution;
use Wurk\Referrals\Support\Commissions;
use Wurk\Referrals\Support\Programme;
use Wurk\Referrals\Support\Wallets;

final class ReferralsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(ReferralRules::class, Programme::class);
        $this->app->bind(StoreCredit::class, Wallets::class); // the wallet is the business's store credit
    }

    public function boot(): void
    {
        parent::boot();
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
            ['label' => 'Referrals', 'route' => 'tenant.referrals.settings', 'icon' => 'gift', 'section' => 'Settings', 'permission' => 'referrals.manage', 'entitlement' => 'referrals', 'order' => 838],
        ];
    }

    public function permissions(): array
    {
        return [['key' => 'referrals.manage', 'label' => 'Set the referral programme’s rules']];
    }
}
