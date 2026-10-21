<?php

declare(strict_types=1);

namespace Wurk\Audit\Contracts;

/**
 * Records something that happened. Inside a business context the event goes
 * on that business's chain; otherwise (or with platform: true) on the platform chain.
 */
interface AuditLog
{
    /**
     * @param  array<string, mixed>  $changes  before/after or details; secrets are redacted automatically
     * @param  array{actor_id?: string|null, actor_name?: string|null, actor_type?: string, platform?: bool}  $options
     */
    public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $changes = [], array $options = []): void;
}
