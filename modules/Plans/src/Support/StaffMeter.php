<?php

declare(strict_types=1);

namespace Wurk\Plans\Support;

use Wurk\Access\Contracts\TeamSize;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Contracts\UsageMeter;

final readonly class StaffMeter implements UsageMeter
{
    public function __construct(private Entitlements $entitlements, private TeamSize $team) {}

    public function measure(string $tenantId): array
    {
        return ['label' => 'Team members', 'used' => $this->team->count($tenantId), 'included' => ($this->entitlements->limits($tenantId)['staff_limit'] ?? null) ?: null, 'unit' => ''];
    }
}
