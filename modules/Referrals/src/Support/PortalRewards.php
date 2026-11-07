<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Referrals\Contracts\CustomerRewards;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class PortalRewards implements CustomerRewards
{
    public function __construct(private ReferralRules $rules, private Attribution $attribution, private Commissions $commissions, private Wallets $wallets,
        private ContactBook $contacts, private TenantContext $context) {}

    public function forContact(string $contactId): ?array
    {
        if (! $this->rules->rules()['enabled']) {
            return null;
        }
        $me = $this->attribution->member($contactId);
        $people = ReferralMember::query()->where('referrer_id', $me->id)->orderByDesc('referred_at')->limit(100)->get();
        $names = $this->contacts->names(array_values(array_map('strval', $people->pluck('contact_id')->all())));

        return [
            'code' => $me->code,
            'link' => route('referrals.link', ['handle' => $this->context->current()->handle(), 'code' => $me->code]),
            // First names only: customers see who they brought, not their details.
            'referred' => array_values(array_map(fn (ReferralMember $m): array => ['name' => explode(' ', trim($names[$m->contact_id] ?? 'Someone'))[0], 'since' => $m->referred_at ?? $m->created_at], $people->all())),
            'pending_cents' => $this->commissions->balance($me->id)['pending'],
            'wallet_cents' => $this->wallets->balance($me->id),
            'expiry_months' => $this->wallets->rules()['expiry_months'],
        ];
    }

    public function statement(string $contactId): array
    {
        $member = ReferralMember::query()->where('contact_id', $contactId)->first();
        if ($member === null) {
            return [];
        }

        return array_values(DB::connection($this->context->connectionName())->table('referral_ledger')->where('tenant_id', $this->context->id())
            ->where('member_id', $member->id)->orderBy('created_at')->get()
            ->map(fn ($l) => ['at' => CarbonImmutable::parse((string) $l->created_at), 'kind' => (string) $l->kind, 'cents' => (int) $l->cents,
                'available_at' => CarbonImmutable::parse((string) $l->available_at)])->all());
    }

    public function payOut(string $contactId, int $cents, string $note): int
    {
        $member = ReferralMember::query()->where('contact_id', $contactId)->first();
        $cents = $member === null ? 0 : min($cents, $this->wallets->balance($member->id));
        if ($member === null || $cents <= 0) {
            return 0;
        }
        DB::connection($this->context->connectionName())->table('referral_ledger')->insert(['id' => (string) Str::ulid(),
            'tenant_id' => $this->context->id(), 'member_id' => $member->id, 'purchase_id' => null, 'level' => 0, 'kind' => 'paid_out', 'cents' => -$cents, 'bp' => null,
            'available_at' => now(), 'created_at' => now(), 'source_type' => 'payout', 'source_id' => null]);
        app(AuditLog::class)->record('referrals.paid_out', 'referral_member', $member->id, ['cents' => $cents, 'note' => mb_substr($note, 0, 120)]);

        return $cents;
    }

    public function close(string $contactId): void
    {
        $member = ReferralMember::query()->where('contact_id', $contactId)->first();
        if ($member === null) {
            return;
        }
        $db = DB::connection($this->context->connectionName());
        $db->transaction(function () use ($db, $member): void {
            // Pending rewards are cancelled each with its own date, so pending and available stay correct.
            $pending = $db->table('referral_ledger')->where('tenant_id', $this->context->id())->where('member_id', $member->id)->where('available_at', '>', now())
                ->selectRaw('available_at, sum(cents) as cents')->groupBy('available_at')->get();
            foreach ($pending as $p) {
                if ((int) $p->cents > 0) {
                    $db->table('referral_ledger')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $member->id,
                        'purchase_id' => null, 'level' => 0, 'kind' => 'forfeited', 'cents' => -(int) $p->cents, 'bp' => null, 'available_at' => $p->available_at,
                        'created_at' => now(), 'source_type' => null, 'source_id' => null]);
                }
            }
            // The link stops working: a code no link can match (links are 7 letters and digits).
            $member->update(['code' => '-'.strtoupper(Str::random(10))]);
        });
        app(AuditLog::class)->record('referrals.closed', 'referral_member', $member->id, []);
    }
}
