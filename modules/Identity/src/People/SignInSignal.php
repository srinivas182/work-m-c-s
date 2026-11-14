<?php

declare(strict_types=1);

namespace Wurk\Identity\People;

use Carbon\CarbonImmutable;
use Wurk\Identity\Contracts\TeamActivity;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Business health (16.5): nobody on the team signing in is the earliest sign a business is drifting away. */
final readonly class SignInSignal
{
    public function __construct(private TeamActivity $activity, private TenantDirectory $tenants) {}

    /** @return list<array{key: string, points: int, reason: string, action: string}> */
    public function __invoke(string $tenantId): array
    {
        $last = $this->activity->lastSignIn([$tenantId])[$tenantId] ?? null;
        if ($last === null) {
            $created = $this->tenants->find($tenantId)?->createdAt();
            $age = $created === null ? 0 : (int) CarbonImmutable::instance($created)->diffInDays(now());

            return $age < 7 ? [] : [['key' => 'never_signed_in', 'points' => 30, 'reason' => "No one has signed in since the business was set up {$age} days ago",
                'action' => 'Call the owner and offer a 15-minute walk-through']];
        }
        $days = (int) CarbonImmutable::parse($last)->diffInDays(now());

        return match (true) {
            $days >= 30 => [['key' => 'no_sign_in', 'points' => 30, 'reason' => "No one has signed in for {$days} days", 'action' => 'Call the owner — ask what’s getting in the way']],
            $days >= 14 => [['key' => 'no_sign_in', 'points' => 15, 'reason' => "No one has signed in for {$days} days", 'action' => 'Send a check-in email with one quick win']],
            default => [],
        };
    }
}
