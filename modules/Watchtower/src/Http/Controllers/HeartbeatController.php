<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Wurk\Watchtower\Support\Heartbeats;

/**
 * GET /health/heartbeat (Sprint 18.0): for the external uptime checker. 503 when the scheduler has stopped (Watchtower's
 * own every-minute check hasn't run for 5 minutes) — the one failure Watchtower can't email about itself.
 * Separate from /health/ready so a stopped scheduler never takes web servers out of the load balancer.
 */
final class HeartbeatController
{
    public const STALE_MINUTES = 5;

    public function __invoke(): JsonResponse
    {
        $last = Heartbeats::pulse();
        $ok = $last !== null && CarbonImmutable::parse($last, (string) config('app.timezone'))->greaterThan(now()->subMinutes(self::STALE_MINUTES));

        return new JsonResponse(['ok' => $ok, 'scheduler' => $ok ? 'running' : ($last === null ? 'never ran' : 'stopped')], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
