<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Agents\Models\Agent;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Contracts\SubscriptionPayments;
use Wurk\Billing\Contracts\WurkRefundWindow;

/**
 * Agent commission on WURK subscription payments (Sprint 14.3): excluding VAT, the business's agent earns "self",
 * their sponsor level 1, and so on up — at the super admin's percentages (0% until set) — each earner only within
 * their own commission period (from when the business joined). Suspended agents earn nothing; levels don't shift.
 * Available once WURK's refund window closes. Append-only; once per payment.
 */
final readonly class AgentCommission
{
    public const MAX_LEVELS = 7;

    public function __construct(private SubscriptionPayments $payments, private AuditLog $audit) {}

    /** @return list<int> [self, level 1, …] in basis points */
    public function percentages(): array
    {
        $json = DB::table('agent_commission_settings')->where('id', 1)->value('level_bp');

        return $json === null ? [0] : array_values(array_map('intval', (array) json_decode((string) $json, true)));
    }

    /** @param list<int> $levelBp */
    public function setPercentages(array $levelBp): void
    {
        $before = $this->percentages();
        DB::table('agent_commission_settings')->updateOrInsert(['id' => 1], ['level_bp' => json_encode(array_values($levelBp)), 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('agents.percentages', 'agent_commission_settings', '1', ['before' => $before, 'after' => array_values($levelBp)]);
    }

    public function refundDays(): int
    {
        return app(WurkRefundWindow::class)->refundDays(); // WURK's refund window — the super admin's setting (14.4)
    }

    public function onPayment(string $paymentId): void
    {
        $paid = $this->payments->paidSubscription($paymentId);
        $attribution = $paid === null ? null : DB::table('agent_businesses')->where('tenant_id', $paid['tenant_id'])->first();
        if ($paid === null || $attribution === null || $paid['excl_cents'] <= 0) {
            return;
        }
        $joined = CarbonImmutable::parse((string) $attribution->attributed_at);
        $available = CarbonImmutable::instance($paid['paid_at'])->addDays($this->refundDays());
        $bp = $this->percentages();
        DB::transaction(function () use ($paid, $paymentId, $attribution, $joined, $available, $bp): void {
            if (DB::table('agent_ledger')->where('payment_id', $paymentId)->where('kind', 'earned')->lockForUpdate()->exists()) {
                return; // once per payment
            }
            $agent = Agent::query()->find((string) $attribution->agent_id);
            foreach (array_slice($bp, 0, self::MAX_LEVELS + 1) as $level => $rate) {
                if ($agent === null) {
                    break;
                }
                $within = $agent->commission_months === null || CarbonImmutable::instance($paid['paid_at'])->lt($joined->addMonths($agent->commission_months));
                $cents = intdiv($paid['excl_cents'] * $rate, 10000);
                if ($agent->status === 'active' && $within && $cents > 0) {
                    DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $agent->id, 'tenant_id' => $paid['tenant_id'], 'payment_id' => $paymentId,
                        'level' => $level, 'kind' => 'earned', 'cents' => $cents, 'bp' => $rate, 'available_at' => $available, 'reference' => 'payment', 'created_at' => now()]);
                }
                $agent = $agent->sponsor_id === null ? null : Agent::query()->find((string) $agent->sponsor_id);
            }
        });
    }

    /**
     * A credit note within the refund window (Sprint 14.4): reverse in proportion to what the invoice has now been credited —
     * cumulatively, so the last credit lands exactly on zero. Still pending, so nothing paid is touched.
     */
    public function onCredit(string $paymentId, int $creditedCents, int $invoiceTotalCents): void
    {
        $this->reverse($paymentId, fn (int $earned) => $creditedCents >= $invoiceTotalCents ? 0 : $earned - intdiv($earned * $creditedCents, max(1, $invoiceTotalCents)), 'credit:'.$creditedCents, false);
    }

    /** A chargeback (decision C): reverse in full; if already payable, now — the agent's balance can go negative and is recovered from future commission. */
    public function onChargeback(string $paymentId): void
    {
        $this->reverse($paymentId, fn (int $earned) => 0, 'chargeback', true);
    }

    /** @param \Closure(int): int $keep how much of an earned amount should remain */
    private function reverse(string $paymentId, \Closure $keep, string $reference, bool $chargeback): void
    {
        DB::transaction(function () use ($paymentId, $keep, $reference, $chargeback): void {
            $lines = DB::table('agent_ledger')->where('payment_id', $paymentId)->whereIn('kind', ['earned', 'reversed'])->lockForUpdate()->get();
            foreach ($lines->where('kind', 'earned') as $earned) {
                $net = (int) $lines->where('agent_id', $earned->agent_id)->where('level', $earned->level)->sum('cents');
                $delta = $keep((int) $earned->cents) - $net;
                if ($delta >= 0 || DB::table('agent_ledger')->where('payment_id', $paymentId)->where('agent_id', $earned->agent_id)->where('level', $earned->level)->where('kind', 'reversed')->where('reference', $reference)->exists()) {
                    continue;
                }
                $available = CarbonImmutable::parse((string) $earned->available_at);
                DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $earned->agent_id, 'tenant_id' => $earned->tenant_id, 'payment_id' => $paymentId,
                    'level' => $earned->level, 'kind' => 'reversed', 'cents' => $delta, 'bp' => $earned->bp, 'reference' => $reference, 'created_at' => now(),
                    'available_at' => $chargeback && $available->lte(now()) ? now() : $available]);
            }
        });
    }

    /** @return array{pending: int, available: int, paid_out: int} */
    public function balance(string $agentId): array
    {
        $rows = DB::table('agent_ledger')->where('agent_id', $agentId)->get(['kind', 'cents', 'available_at']);
        $now = now();
        $pending = (int) $rows->filter(fn ($r) => $now->lt($r->available_at))->sum('cents');

        return ['pending' => $pending, 'available' => (int) $rows->sum('cents') - $pending, 'paid_out' => -(int) $rows->where('kind', 'paid_out')->sum('cents')];
    }

    /** When an agent's balance went negative through a chargeback (decision C: recovered from future commission; flagged after 90 days). */
    public function owesSince(string $agentId): ?CarbonImmutable
    {
        if ($this->balance($agentId)['available'] >= 0) {
            return null;
        }
        $at = DB::table('agent_ledger')->where('agent_id', $agentId)->where('kind', 'reversed')->where('reference', 'chargeback')->min('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }
}
