<?php

declare(strict_types=1);

namespace Wurk\Referrals\Contracts;

/** The current business's referral programme rules (Sprint 13.1), read by attribution, commission and wallets. */
interface ReferralRules
{
    /**
     * @return array{enabled: bool, self_bp: int, level_bp: list<int>, attribution: string, link_days: int}
     */
    public function rules(): array;

    /**
     * The package's guardrails for this business.
     *
     * @return array{max_levels: int, max_bp: int}
     */
    public function limits(): array;
}
