<?php

declare(strict_types=1);

namespace Wurk\Plans\Support;

use Carbon\CarbonImmutable;
use Wurk\Plans\Contracts\Catalog;

/** Business health (16.5): failed payments, suspension, and a trial about to end. */
final readonly class PaymentSignal
{
    public function __construct(private Catalog $catalog) {}

    /** @return list<array{key: string, points: int, reason: string, action: string}> */
    public function __invoke(string $tenantId): array
    {
        $s = $this->catalog->subscriptionOf($tenantId);
        if ($s === null) {
            return [['key' => 'no_package', 'points' => 20, 'reason' => 'No package chosen yet', 'action' => 'Help them choose a package']];
        }
        if ($s['status'] === 'suspended') {
            return [['key' => 'suspended', 'points' => 60, 'reason' => 'Suspended for non-payment', 'action' => 'Call about paying to reopen the site']];
        }
        if ($s['status'] === 'past_due') {
            $since = $s['past_due_since'] === null ? null : (int) CarbonImmutable::parse($s['past_due_since'])->diffInDays(now());

            return [['key' => 'payment_failed', 'points' => 35, 'reason' => 'Payment failed'.($since === null ? '' : " {$since} days ago").' — in the grace period',
                'action' => 'Help them update their card or pay another way']];
        }
        if ($s['trial_ends_at'] !== null) {
            $left = (int) ceil(now()->diffInDays(CarbonImmutable::parse($s['trial_ends_at']), false));
            if ($left >= 0 && $left <= 3) {
                return [['key' => 'trial_ending', 'points' => 10, 'reason' => "Trial ends in {$left} ".($left === 1 ? 'day' : 'days'), 'action' => 'Check they have what they need to stay']];
            }
        }

        return [];
    }
}
