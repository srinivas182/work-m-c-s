<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralPayout;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Payouts (Sprint 13.7): the customer asks, the amount is reserved at once (a payout line in the ledger), the business
 * pays from its own bank and marks it paid — or declines, returning the amount. The account number is encrypted and
 * deleted once the payout is decided; logs never carry it.
 */
final readonly class Payouts
{
    public function __construct(private TenantContext $context, private Wallets $wallets, private AuditLog $audit) {}

    /**
     * @param  array{holder: string, bank: string, account: string, branch: string|null}  $bank
     *
     * @throws \DomainException a message for the customer
     */
    public function request(string $contactId, int $cents, array $bank): ReferralPayout
    {
        $rules = $this->wallets->rules();
        $member = ReferralMember::query()->where('contact_id', $contactId)->first();
        if (! $rules['payouts_allowed'] || $member === null) {
            throw new \DomainException('This business doesn’t pay out rewards — spend them with them instead.');
        }

        return $this->db()->transaction(function () use ($member, $cents, $bank, $rules) {
            $this->db()->table('referral_members')->where('tenant_id', $this->context->id())->where('id', $member->id)->lockForUpdate()->first(); // with spending
            if (ReferralPayout::query()->where('member_id', $member->id)->where('status', 'requested')->exists()) {
                throw new \DomainException('You already have a payout waiting — the business will be in touch.');
            }
            $balance = $this->wallets->balance($member->id);
            if ($cents < $rules['min_payout_cents'] || $cents > $balance) {
                throw new \DomainException($balance < $rules['min_payout_cents']
                    ? 'Payouts start at R'.number_format($rules['min_payout_cents'] / 100, 2).' — you have R'.number_format($balance / 100, 2).'.'
                    : 'Choose an amount from R'.number_format($rules['min_payout_cents'] / 100, 2).' to R'.number_format($balance / 100, 2).'.');
            }
            $digits = (string) preg_replace('/\D/', '', $bank['account']);
            $p = ReferralPayout::query()->create(['member_id' => $member->id, 'cents' => $cents, 'status' => 'requested', 'account_holder' => $bank['holder'],
                'bank' => $bank['bank'], 'account_number' => $digits, 'account_last4' => substr($digits, -4), 'branch_code' => $bank['branch'], 'created_at' => now()]);
            $this->line($member->id, 'paid_out', -$cents, $p->id); // reserved now: can't also be spent at checkout
            $this->audit->record('referrals.payout_requested', 'referral_payout', $p->id, ['cents' => $cents], ['actor_name' => 'Customer']);

            return $p;
        });
    }

    public function markPaid(ReferralPayout $p, string $reference, string $by): void
    {
        $this->open($p);
        $p->update(['status' => 'paid', 'reference' => $reference, 'account_number' => null, 'decided_by_name' => $by, 'decided_at' => now()]);
        $this->audit->record('referrals.payout_paid', 'referral_payout', $p->id, ['cents' => $p->cents, 'reference' => $reference]);
    }

    public function decline(ReferralPayout $p, string $reason, string $by): void
    {
        $this->open($p);
        $this->db()->transaction(function () use ($p, $reason, $by): void {
            $p->update(['status' => 'declined', 'reason' => $reason, 'account_number' => null, 'decided_by_name' => $by, 'decided_at' => now()]);
            $this->line($p->member_id, 'returned', $p->cents, $p->id); // back to the wallet
        });
        $this->audit->record('referrals.payout_declined', 'referral_payout', $p->id, ['cents' => $p->cents]);
    }

    private function open(ReferralPayout $p): void
    {
        if ($p->status !== 'requested') {
            throw new \DomainException('This payout has already been handled.');
        }
    }

    private function line(string $memberId, string $kind, int $cents, string $payoutId): void
    {
        $this->db()->table('referral_ledger')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $memberId, 'purchase_id' => null,
            'level' => 0, 'kind' => $kind, 'cents' => $cents, 'bp' => null, 'available_at' => now(), 'created_at' => now(), 'source_type' => 'payout', 'source_id' => $payoutId]);
    }

    private function db(): Connection
    {
        return DB::connection($this->context->connectionName());
    }
}
