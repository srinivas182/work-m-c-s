<?php

declare(strict_types=1);

namespace Wurk\Credits\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Credits\Events\LowCreditBalance;
use Wurk\Credits\Models\CreditBalance;
use Wurk\Credits\Models\CreditLedgerEntry;
use Wurk\Credits\Models\CreditRate;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class Wallet implements CreditWallet
{
    public const GRANT_TYPES = ['allowance', 'purchase', 'adjustment'];

    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function rateFor(string $channel): int
    {
        $rate = CreditRate::query()
            ->where('country', $this->context->current()->countryCode())
            ->where('channel', $channel)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id') // same start time: the most recently set rate wins (ULIDs are time-ordered)
            ->value('credits_milli');

        if ($rate === null) {
            throw new InvalidArgumentException("No WURK Credits rate for {$channel} in {$this->context->current()->countryCode()}.");
        }

        return (int) $rate;
    }

    public function charge(string $channel, string $key, string $description): int
    {
        $cost = $this->rateFor($channel);

        $entry = $this->write(function (CreditBalance $balance) use ($cost, $channel, $key, $description): array {
            if ($balance->balance_milli < $cost) {
                throw new InsufficientCredits($cost, $balance->balance_milli);
            }

            return ['type' => 'debit', 'amount_milli' => -$cost, 'channel' => $channel, 'key' => $key, 'description' => $description,
                // Allowance is spent first; it expires at the next grant.
                'allowance_delta' => -min($cost, $balance->allowance_milli)];
        }, $key);

        $this->maybeAlert();

        return abs($entry->amount_milli);
    }

    public function refund(string $chargeKey, string $description): void
    {
        $charge = CreditLedgerEntry::query()->where('idempotency_key', $chargeKey)->where('type', 'debit')->first();
        if ($charge === null) {
            return;
        }

        $this->write(fn () => ['type' => 'refund', 'amount_milli' => abs($charge->amount_milli), 'channel' => $charge->channel,
            'key' => 'refund:'.$chargeKey, 'description' => $description, 'allowance_delta' => 0], 'refund:'.$chargeKey);
    }

    public function grant(string $type, int $amountMilli, string $key, string $description, ?string $by = null): void
    {
        if (! in_array($type, self::GRANT_TYPES, true)) {
            throw new InvalidArgumentException("Unknown grant type {$type}.");
        }
        if ($type !== 'adjustment' && $amountMilli <= 0) {
            throw new InvalidArgumentException('Grants must be positive.');
        }

        $this->write(function (CreditBalance $balance) use ($type, $amountMilli, $key, $description, $by): array {
            if ($type === 'adjustment' && $balance->balance_milli + $amountMilli < 0) {
                throw new InsufficientCredits(abs($amountMilli), $balance->balance_milli);
            }

            return ['type' => $type, 'amount_milli' => $amountMilli, 'channel' => null, 'key' => $key, 'description' => $description, 'by' => $by,
                'allowance_delta' => $type === 'allowance' ? $amountMilli : ($type === 'adjustment' && $amountMilli < 0 ? -min(abs($amountMilli), $balance->allowance_milli) : 0)];
        }, $key, beforeAllowance: $type === 'allowance');

        if ($type === 'adjustment') {
            $this->audit->record('credits.adjusted', 'tenant', $this->context->id(), ['amount' => $amountMilli / 1000, 'reason' => $description]);
        }
    }

    public function balance(): array
    {
        $b = CreditBalance::query()->find($this->context->id());
        if ($b === null) {
            return ['balance_milli' => 0, 'allowance_milli' => 0, 'low_balance_milli' => 20000];
        }

        return ['balance_milli' => $b->balance_milli, 'allowance_milli' => $b->allowance_milli, 'low_balance_milli' => $b->low_balance_milli];
    }

    /**
     * One locked, idempotent ledger write that keeps the cached balance in step.
     *
     * @param  callable(CreditBalance): array{type: string, amount_milli: int, channel: string|null, key: string, description: string, allowance_delta: int, by?: string|null}  $plan
     */
    private function write(callable $plan, string $key, bool $beforeAllowance = false): CreditLedgerEntry
    {
        $existing = CreditLedgerEntry::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::connection($this->context->connectionName())->transaction(function () use ($plan, $key, $beforeAllowance): CreditLedgerEntry {
            CreditBalance::query()->insertOrIgnore(['tenant_id' => $this->context->id(), 'balance_milli' => 0, 'allowance_milli' => 0, 'low_balance_milli' => 20000, 'updated_at' => now()]);
            $balance = CreditBalance::query()->whereKey($this->context->id())->lockForUpdate()->firstOrFail();

            // Re-check under the lock: a concurrent retry may have written it.
            $existing = CreditLedgerEntry::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            if ($beforeAllowance && $balance->allowance_milli > 0) {
                // Unused allowance does not roll over (Sprint 2 decision 6).
                $this->append($balance, 'expiry', -$balance->allowance_milli, null, 'expiry:'.$key, 'Unused monthly allowance expired', null, -$balance->allowance_milli);
            }

            $p = $plan($balance);

            return $this->append($balance, $p['type'], $p['amount_milli'], $p['channel'], $p['key'], $p['description'], $p['by'] ?? null, $p['allowance_delta']);
        }, attempts: 3);
    }

    private function append(CreditBalance $balance, string $type, int $amount, ?string $channel, string $key, string $description, ?string $by, int $allowanceDelta): CreditLedgerEntry
    {
        $balance->balance_milli += $amount;
        $balance->allowance_milli = max(0, $balance->allowance_milli + $allowanceDelta);
        if ($amount > 0 && $balance->balance_milli >= $balance->low_balance_milli) {
            $balance->low_alerted_at = null; // topped up: alert again next time it runs low
        }
        $balance->save();

        return CreditLedgerEntry::query()->create([
            'type' => $type, 'amount_milli' => $amount, 'balance_after_milli' => $balance->balance_milli,
            'idempotency_key' => $key, 'channel' => $channel, 'description' => mb_substr($description, 0, 255), 'created_by' => $by,
        ]);
    }

    private function maybeAlert(): void
    {
        $balance = CreditBalance::query()->find($this->context->id());
        if ($balance !== null && $balance->balance_milli < $balance->low_balance_milli && $balance->low_alerted_at === null) {
            $balance->forceFill(['low_alerted_at' => now()])->save();
            event(new LowCreditBalance($this->context->id(), $balance->balance_milli, $balance->low_balance_milli));
        }
    }
}
