<?php

declare(strict_types=1);

namespace Wurk\Plans\Contracts;

/**
 * Something a package includes and a business uses (storage, staff…), shown on Plan & usage.
 * Modules register meters with the "wurk.usage-meters" container tag.
 */
interface UsageMeter
{
    public const TAG = 'wurk.usage-meters';

    /** @return array{label: string, used: int|float, included: int|float|null, unit: string} */
    public function measure(string $tenantId): array;
}
