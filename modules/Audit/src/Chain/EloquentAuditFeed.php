<?php

declare(strict_types=1);

namespace Wurk\Audit\Chain;

use Wurk\Audit\Contracts\AuditFeed;
use Wurk\Audit\Models\PlatformAuditEvent;

final class EloquentAuditFeed implements AuditFeed
{
    public function recentPlatform(int $limit = 8): array
    {
        return array_values(PlatformAuditEvent::query()->where('scope', Recorder::PLATFORM)->orderByDesc('seq')->limit($limit)->get()
            ->map(fn (PlatformAuditEvent $e) => ['at' => $e->occurred_at->toIso8601String(), 'actor' => $e->actor_name ?? ucfirst($e->actor_type), 'action' => $e->action])
            ->all());
    }
}
