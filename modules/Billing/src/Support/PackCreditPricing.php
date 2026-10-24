<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Wurk\Billing\Models\CreditPack;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\CreditPricing;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantDirectory;

final readonly class PackCreditPricing implements CreditPricing
{
    public function __construct(private Countries $countries, private TenantDirectory $tenants, private Catalog $catalog) {}

    public function randPerCredit(string $tenantId): ?float
    {
        $best = CreditPack::query()->where('active', true)->where('credits', '>', 0)->get()->map(fn (CreditPack $p) => $p->price_cents / $p->credits)->min();

        return $best === null ? null : round($this->withVat((float) $best, $tenantId) / 100, 2);
    }

    public function nextPayment(string $tenantId): ?array
    {
        $sub = $this->catalog->subscriptionOf($tenantId);
        $date = $sub['current_period_end'] ?? $sub['trial_ends_at'] ?? null;

        return $sub === null || $date === null ? null : ['date' => (string) $date, 'total_cents' => (int) round($this->withVat((float) $sub['period_cents'], $tenantId))];
    }

    private function withVat(float $cents, string $tenantId): float
    {
        $vatBp = (int) ($this->countries->find($this->tenants->find($tenantId)?->countryCode() ?? 'ZA')['vat_bp'] ?? 1500);

        return $cents * (1 + $vatBp / 10000);
    }
}
