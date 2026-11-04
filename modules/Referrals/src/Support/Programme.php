<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class Programme implements ReferralRules
{
    public function __construct(private TenantContext $context, private Entitlements $entitlements) {}

    public function rules(): array
    {
        $p = ReferralProgramme::query()->first();

        return [
            'enabled' => $p !== null && $p->enabled, 'self_bp' => $p === null ? 0 : $p->self_bp, 'level_bp' => $p === null ? [] : array_values(array_map('intval', $p->level_bp)),
            'attribution' => $p === null ? 'first' : $p->attribution, 'link_days' => $p === null ? 30 : $p->link_days, 'return_days' => $p === null ? 14 : $p->return_days,
        ];
    }

    public function limits(): array
    {
        $l = $this->entitlements->limits($this->context->id()) ?? [];

        return ['max_levels' => max(0, min(7, (int) ($l['referral_max_levels'] ?? 0))), 'max_bp' => max(0, (int) ($l['referral_max_commission_bp'] ?? 0))];
    }
}
