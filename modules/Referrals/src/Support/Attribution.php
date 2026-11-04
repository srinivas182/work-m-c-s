<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Illuminate\Support\Facades\Cookie;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Models\ReferralMember;

/**
 * Codes and who referred whom. A referral link (/r/CODE) remembers the code in a first-party cookie for the
 * programme's link validity; when that visitor becomes a customer, the code's owner is recorded as their referrer —
 * once (first referrer) or each time (last referrer). Self-referral and loops are refused; changes are logged.
 */
final readonly class Attribution
{
    public const COOKIE = 'wurk_ref';

    /** No look-alike characters (0/O, 1/I/L). */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(private ReferralRules $rules, private AuditLog $audit) {}

    /** The customer's membership (and code), created the first time. */
    public function member(string $contactId): ReferralMember
    {
        $existing = ReferralMember::query()->where('contact_id', $contactId)->first();
        if ($existing !== null) {
            return $existing;
        }
        do {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (ReferralMember::query()->where('code', $code)->exists());

        return ReferralMember::query()->create(['contact_id' => $contactId, 'code' => $code]);
    }

    public function byCode(string $code): ?ReferralMember
    {
        return ReferralMember::query()->where('code', mb_strtoupper(trim($code)))->first();
    }

    /** Record who referred this customer, from the referral cookie (if any) — within the programme's rules. */
    public function attach(string $contactId, ?string $code): void
    {
        $rules = $this->rules->rules();
        if (! $rules['enabled']) {
            return;
        }
        $member = $this->member($contactId); // every customer of a running programme gets a code
        $referrer = $code === null ? null : $this->byCode($code);
        if ($referrer === null || $referrer->id === $member->id) {
            return; // no (valid) referral, or a self-referral
        }
        if ($member->referrer_id !== null && ($rules['attribution'] === 'first' || $member->referrer_id === $referrer->id)) {
            return; // already credited
        }
        if ($this->wouldLoop($member->id, $referrer)) {
            return; // the referrer was (indirectly) referred by this customer
        }
        $from = $member->referrer_id;
        $member->update(['referrer_id' => $referrer->id, 'referred_at' => now()]);
        $this->audit->record('referrals.referred', 'referral_member', $member->id, ['referrer' => $referrer->id, 'previous' => $from]);
    }

    /** The referral code from the current request's cookie, if any. */
    public function codeFromRequest(): ?string
    {
        $c = app()->bound('request') ? request()->cookie(self::COOKIE) : null;

        return is_string($c) && preg_match('/^['.self::ALPHABET.']{7}$/', $c) === 1 ? $c : null;
    }

    public function cookie(string $code, int $days): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(self::COOKIE, $code, $days * 24 * 60, '/', null, request()->isSecure(), true, false, 'lax');
    }

    private function wouldLoop(string $memberId, ReferralMember $referrer): bool
    {
        $seen = 0;
        for ($m = $referrer; $m !== null && $seen < 50; $seen++) { // walk up the referrer's chain
            if ($m->id === $memberId) {
                return true;
            }
            $m = $m->referrer_id === null ? null : ReferralMember::query()->find($m->referrer_id);
        }

        return false;
    }
}
