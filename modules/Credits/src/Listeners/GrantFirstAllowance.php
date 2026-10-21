<?php

declare(strict_types=1);

namespace Wurk\Credits\Listeners;

use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Events\SubscriptionChanged;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** A new business gets its first month's credits straight away (same key as the daily job). */
final readonly class GrantFirstAllowance
{
    public function __construct(private TenantDirectory $directory, private TenantContext $context, private CreditWallet $wallet) {}

    public function handle(SubscriptionChanged $event): void
    {
        $tenant = $this->directory->find($event->tenantId);
        if (! $event->isNew || $event->monthlyCredits <= 0 || $tenant === null) {
            return;
        }

        $this->context->run($tenant, fn () => $this->wallet->grant('allowance', $event->monthlyCredits * 1000, 'allowance:'.now()->format('Y-m'), "Monthly allowance ({$event->monthlyCredits} credits)"));
    }
}
