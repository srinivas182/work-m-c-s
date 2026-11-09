<?php

declare(strict_types=1);

namespace Wurk\Plans\Contracts;

/** Each business's subscription as it stood on a day (Sprint 15.5a — from the daily snapshots, 15.4a). */
interface SubscriptionHistory
{
    /** @return list<array{tenant_id: string, status: string, package_id: string|null, period: string|null, mrr_cents: int, country: string}> */
    public function on(\DateTimeInterface $day): array;

    /** @return array<string, string> package id => name */
    public function packageNames(): array;
}
