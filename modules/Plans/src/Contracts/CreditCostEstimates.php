<?php

declare(strict_types=1);

namespace Wurk\Plans\Contracts;

/**
 * What one WURK Credit costs WURK, per thing credits buy — for the package cost tip.
 * Implemented by the AI module (it knows provider and model costs); optional.
 */
interface CreditCostEstimates
{
    /** @return array<string, array{label: string, zar_per_credit: float|null}> null = cost not set yet */
    public function perCredit(string $country = 'ZA'): array;

    /** @return array{fx_zar_per_usd: float, fx_buffer_pct: float, target_margin_pct: float} */
    public function assumptions(): array;
}
