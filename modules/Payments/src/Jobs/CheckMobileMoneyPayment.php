<?php

declare(strict_types=1);

namespace Wurk\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Support\MobileMoney;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Keeps asking the provider about a mobile money prompt until it is paid, fails or expires (Sprint 22.2), so a payment
 * is settled even when the provider's callback never arrives and the customer has closed the page.
 */
final class CheckMobileMoneyPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rounds of asking (every 20 seconds, a little past the prompt's expiry). */
    public const ROUNDS = 12;

    public function __construct(public readonly string $tenantId, public readonly string $reference, public readonly int $round = 1)
    {
        $this->onQueue('critical');
    }

    public function handle(TenantDirectory $tenants, TenantContext $context): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function (): void {
            $attempt = PaymentAttempt::query()->find($this->reference);
            if ($attempt !== null && $attempt->status === 'pending' && app(MobileMoney::class)->check($attempt)->state === 'pending' && $this->round < self::ROUNDS) {
                self::dispatch($this->tenantId, $this->reference, $this->round + 1)->delay(now()->addSeconds(20));
            }
        });
    }
}
