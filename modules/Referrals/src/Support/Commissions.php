<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Rewards on purchases (Sprint 13.3): the buyer's own cashback (level 0) and each referrer up the chain (levels 1..n),
 * within the package's current limits. Append-only ledger; whole cents (rounded down when earned); refunds reverse
 * proportionally, and a full refund reverses exactly what's left.
 */
final readonly class Commissions
{
    public function __construct(private TenantContext $context, private ReferralRules $rules, private ContactBook $contacts, private Attribution $attribution, private AuditLog $audit) {}

    public function purchase(string $sourceType, string $sourceId, int $paidCents, int $commissionableCents, ?string $branchId): void
    {
        $rules = $this->rules->rules();
        $contact = $this->contacts->contactFor($sourceType, $sourceId);
        if (! $rules['enabled'] || $contact === null || $commissionableCents <= 0) {
            return;
        }
        $db = $this->db();
        $tenant = $this->context->id();
        $db->transaction(function () use ($db, $tenant, $rules, $contact, $sourceType, $sourceId, $paidCents, $commissionableCents, $branchId): void {
            if ($db->table('referral_purchases')->where('tenant_id', $tenant)->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->exists()) {
                return; // already rewarded (e.g. a resent payment notice)
            }
            $buyer = $this->attribution->member($contact);
            $purchaseId = (string) Str::ulid();
            $db->table('referral_purchases')->insert(['tenant_id' => $tenant, 'source_type' => $sourceType, 'source_id' => $sourceId, 'id' => $purchaseId, 'member_id' => $buyer->id, 'paid_cents' => $paidCents,
                'commissionable_cents' => $commissionableCents, 'branch_id' => $branchId, 'occurred_at' => now()]);
            $available = now()->addDays($rules['return_days']);
            $limits = $this->rules->limits(); // today's package limits apply (a downgrade takes effect)
            $budget = $limits['max_bp'];
            $earners = [[0, $buyer, $rules['self_bp']]];
            $member = $buyer;
            foreach (array_slice($rules['level_bp'], 0, $limits['max_levels']) as $i => $bp) {
                $member = $member->referrer_id === null ? null : ReferralMember::query()->find($member->referrer_id);
                if ($member === null) {
                    break;
                }
                $earners[] = [$i + 1, $member, $bp];
            }
            foreach ($earners as [$level, $earner, $bp]) {
                $bp = min($bp, $budget);
                $budget -= $bp;
                $cents = intdiv($commissionableCents * $bp, 10000);
                if ($cents > 0) {
                    $db->table('referral_ledger')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'member_id' => $earner->id, 'purchase_id' => $purchaseId,
                        'level' => $level, 'kind' => 'earned', 'cents' => $cents, 'bp' => $bp, 'available_at' => $available, 'created_at' => now()]);
                }
            }
        });
    }

    public function refund(string $sourceType, string $sourceId, int $refundedCents): void
    {
        $db = $this->db();
        $tenant = $this->context->id();
        $db->transaction(function () use ($db, $tenant, $sourceType, $sourceId, $refundedCents): void {
            $p = $db->table('referral_purchases')->where('tenant_id', $tenant)->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->first();
            if ($p === null || $refundedCents <= 0) {
                return;
            }
            $refunded = min((int) $p->paid_cents, (int) $p->refunded_cents + $refundedCents);
            $db->table('referral_purchases')->where('tenant_id', $tenant)->where('id', $p->id)->update(['refunded_cents' => $refunded]);
            $full = $refunded >= (int) $p->paid_cents;
            $lines = $db->table('referral_ledger')->where('tenant_id', $tenant)->where('purchase_id', $p->id)->get()->groupBy(fn ($l) => $l->member_id.'|'.$l->level);
            foreach ($lines as $group) {
                $earned = (int) $group->where('kind', 'earned')->sum('cents');
                $left = $earned + (int) $group->where('kind', 'reversed')->sum('cents'); // reversals are negative
                // Reverse in proportion to what's been refunded so far; a full refund reverses exactly what's left.
                $target = $full ? 0 : $earned - intdiv($earned * $refunded, (int) $p->paid_cents);
                $cut = $left - max(0, $target);
                $first = $group->first();
                if ($cut > 0 && $first !== null) {
                    $db->table('referral_ledger')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'member_id' => $first->member_id, 'purchase_id' => $p->id,
                        'level' => $first->level, 'kind' => 'reversed', 'cents' => -$cut, 'bp' => null,
                        // Same date as the reward it cancels: a pending reward's reversal reduces pending, an available one's reduces available.
                        'available_at' => $first->available_at, 'created_at' => now()]);
                }
            }
            $this->audit->record('referrals.reversed', 'referral_purchase', (string) $p->id, ['refunded_cents' => $refundedCents, 'full' => $full]);
        });
    }

    /**
     * A member's rewards: pending (not yet available) and available (net of reversals).
     *
     * @return array{pending: int, available: int}
     */
    public function balance(string $memberId): array
    {
        $rows = $this->db()->table('referral_ledger')->where('tenant_id', $this->context->id())->where('member_id', $memberId)->get(['cents', 'available_at']);
        $now = now();
        $pending = (int) $rows->filter(fn ($r) => $now->lt($r->available_at))->sum('cents');

        return ['pending' => $pending, 'available' => (int) $rows->sum('cents') - $pending];
    }

    private function db(): Connection
    {
        return DB::connection($this->context->connectionName());
    }
}
