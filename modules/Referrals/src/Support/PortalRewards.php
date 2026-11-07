<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
}
