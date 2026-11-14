<?php

declare(strict_types=1);

namespace Wurk\Credits\Support;

use Wurk\Credits\Contracts\CreditWallet;

/** Business health (16.5): out of credits while they use messages or AI — reminders and campaigns stop. Runs in the business. */
final readonly class CreditsSignal
{
    public function __construct(private CreditWallet $wallet) {}

    /** @return list<array{key: string, points: int, reason: string, action: string}> */
    public function __invoke(string $tenantId): array
    {
        $b = $this->wallet->balance();
        $used = array_sum(array_column($this->wallet->usageThisMonth(), 'milli'));
        if ($b['balance_milli'] <= 0 && $used > 0) {
            return [['key' => 'out_of_credits', 'points' => 15, 'reason' => 'Out of credits — reminders and messages have stopped', 'action' => 'Suggest a credit pack or auto top-up']];
        }
        if ($b['low_balance_milli'] > 0 && $b['balance_milli'] < $b['low_balance_milli'] && $used > 0) {
            return [['key' => 'low_credits', 'points' => 5, 'reason' => 'Credits running low', 'action' => 'Mention auto top-up']];
        }

        return [];
    }
}
