<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Carbon\CarbonImmutable;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\SiteLaunch;

/** Business health (16.5): a website never published a week after signing up. */
final readonly class LaunchSignal
{
    public function __construct(private TenantDirectory $tenants) {}

    /** @return list<array{key: string, points: int, reason: string, action: string}> */
    public function __invoke(string $tenantId): array
    {
        if (SiteLaunch::query()->whereKey($tenantId)->exists()) {
            return [];
        }
        $created = $this->tenants->find($tenantId)?->createdAt();
        $age = $created === null ? 0 : (int) CarbonImmutable::instance($created)->diffInDays(now());

        return $age < 7 ? [] : [['key' => 'site_not_published', 'points' => 20, 'reason' => "Website not published {$age} days after signing up",
            'action' => 'Offer a setup call — or “Write my website” with AI']];
    }
}
