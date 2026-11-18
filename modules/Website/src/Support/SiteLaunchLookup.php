<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Wurk\Website\Contracts\SiteLaunches;
use Wurk\Website\Models\SiteLaunch;

final class SiteLaunchLookup implements SiteLaunches
{
    public function launched(string $tenantId): bool
    {
        return SiteLaunch::query()->whereKey($tenantId)->exists();
    }
}
