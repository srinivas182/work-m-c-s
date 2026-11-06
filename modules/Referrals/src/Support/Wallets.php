<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * The customer's wallet with this business (Sprint 13.4b): available rewards, less what's been spent, paid out or
 * expired — all lines in the append-only referral ledger. Spending is limited by the balance and the most of an
 * order a wallet may pay; a refund gives the wallet part back; unused rewards expire oldest first.
 */
final readonly class Wallets implements StoreCredit
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    /** @return array{payouts_allowed: bool, min_payout_cents: int, max_wallet_bp: int, expiry_months: int} */
    public function rules(): array
    {
        $p = ReferralProgramme::query()->first();

        return ['payouts_allowed' => (bool) ($p?->getAttribute('payouts_allowed') ?? false), 'min_payout_cents' => (int) ($p?->getAttribute('min_payout_cents') ?? 10000),
            'max_wallet_bp' => (int) ($p?->getAttribute('max_wallet_bp') ?? 5000), 'expiry_months' => (int) ($p?->getAttribute('expiry_months') ?? 12)];
    }

    public function available(string $contactId): int
    {
        $member = ReferralMember::query()->where('contact_id', $contactId)->first();

        return $member === null ? 0 : $this->balance($member->id);
    }

    /** A member's usable balance: every line already available (credits and debits). */
    public function balance(string $memberId): int
    {
        return max(0, (int) $this->db()->table('referral_ledger')->where('tenant_id', $this->context->id())->where('member_id', $memberId)->where('available_at', '<=', now())->sum('cents'));
    }

    public function usableFor(string $contactId, int $orderTotalCents): int
    {
        return min($this->available($contactId), intdiv(max(0, $orderTotalCents) * $this->rules()['max_wallet_bp'], 10000));
    }

    public function spend(string $contactId, int $cents, string $sourceType, string $sourceId, int $orderTotalCents): void
    {
        $member = ReferralMember::query()->where('contact_id', $contactId)->first();
        if ($cents <= 0 || $member === null) {
            throw new \DomainException('There’s no credit to use here.');
        }
        $this->db()->transaction(function () use ($member, $cents, $sourceType, $sourceId, $orderTotalCents): void {
            $this->db()->table('referral_members')->where('tenant_id', $this->context->id())->where('id', $member->id)->lockForUpdate()->first(); // one spend at a time
            $usable = min($this->balance($member->id), intdiv($orderTotalCents * $this->rules()['max_wallet_bp'], 10000));
            if ($cents > $usable) {
                throw new \DomainException('Only R'.number_format($usable / 100, 2).' of the wallet can be used on this order.');
            }
            $this->line($member->id, 'spent', -$cents, $sourceType, $sourceId);
        });
        $this->audit->record('referrals.wallet_spent', $sourceType, $sourceId, ['cents' => $cents]);
    }

    public function giveBack(string $sourceType, string $sourceId, int $cents): void
    {
        $db = $this->db();
        $spent = $db->table('referral_ledger')->where('tenant_id', $this->context->id())->where('source_type', $sourceType)->where('source_id', $sourceId)->whereIn('kind', ['spent', 'returned'])->get();
        $first = $spent->firstWhere('kind', 'spent');
        $owed = -(int) $spent->sum('cents'); // spent on this order, less what's already been given back
        $back = min(max(0, $cents), $owed);
        if ($first === null || $back <= 0) {
            return;
        }
        $this->line((string) $first->member_id, 'returned', $back, $sourceType, $sourceId);
        $this->audit->record('referrals.wallet_returned', $sourceType, $sourceId, ['cents' => $back]);
    }

    /**
     * Expire unused rewards older than the business's expiry, oldest first: what's still unspent of the rewards that
     * became available before the cutoff. Safe to run every day.
     *
     * @return int cents expired
     */
    public function expire(): int
    {
        $months = $this->rules()['expiry_months'];
        if ($months === 0) {
            return 0;
        }
        $cutoff = now()->subMonths($months);
        $total = 0;
        $db = $this->db();
        foreach ($db->table('referral_ledger')->where('tenant_id', $this->context->id())->distinct()->pluck('member_id') as $memberId) {
            $lines = $db->table('referral_ledger')->where('tenant_id', $this->context->id())->where('member_id', $memberId)->where('available_at', '<=', now())->get();
            $old = (int) $lines->filter(fn ($l) => $l->cents > 0 && $cutoff->gte($l->available_at))->sum('cents');
            $debits = -(int) $lines->filter(fn ($l) => $l->cents < 0)->sum('cents'); // spent, paid out, expired, reversed — oldest credits used first
            $expiring = $old - $debits;
            if ($expiring > 0) {
                $this->line((string) $memberId, 'expired', -$expiring, null, null);
                $this->audit->record('referrals.wallet_expired', 'referral_member', (string) $memberId, ['cents' => $expiring, 'months' => $months]);
                $total += $expiring;
            }
        }

        return $total;
    }

    /** Signed with the app key; bound to this business and customer; valid 15 minutes. */
    public function passFor(string $contactId): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode(['t' => $this->context->id(), 'c' => $contactId, 'e' => now()->addMinutes(15)->getTimestamp()])), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, 'wallet-pass|'.(string) config('app.key'));
    }

    public function contactFromPass(string $pass): ?string
    {
        [$body, $sig] = array_pad(explode('.', $pass, 2), 2, '');
        if ($body === '' || ! hash_equals(hash_hmac('sha256', $body, 'wallet-pass|'.(string) config('app.key')), $sig)) {
            return null; // forged or altered
        }
        $d = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($d) || ($d['t'] ?? null) !== $this->context->id() || (int) ($d['e'] ?? 0) < now()->getTimestamp()) {
            return null; // another business's, or expired
        }

        return is_string($d['c'] ?? null) ? $d['c'] : null;
    }

    private function line(string $memberId, string $kind, int $cents, ?string $sourceType, ?string $sourceId): void
    {
        $this->db()->table('referral_ledger')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $memberId, 'purchase_id' => null,
            'level' => 0, 'kind' => $kind, 'cents' => $cents, 'bp' => null, 'available_at' => now(), 'created_at' => now(), 'source_type' => $sourceType, 'source_id' => $sourceId]);
    }

    private function db(): Connection
    {
        return DB::connection($this->context->connectionName());
    }
}
