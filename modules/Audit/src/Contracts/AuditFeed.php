<?php

declare(strict_types=1);

namespace Wurk\Audit\Contracts;

/** Read-only access to recent platform audit events for dashboards. */
interface AuditFeed
{
    /** @return list<array{at: string, actor: string, action: string}> */
    public function recentPlatform(int $limit = 8): array;
}
