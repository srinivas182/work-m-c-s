<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;
use Wurk\Watchtower\Support\Uptime;

/**
 * POST /_wurk/uptime/{token} (Sprint 18.0b): the external uptime checker reports an outage starting or ending.
 * Understands Better Stack's incident webhook, UptimeRobot's alert webhook, and a plain {monitor, status, cause}.
 * The token (WATCHTOWER_UPTIME_TOKEN) is the only secret; without it set, the endpoint doesn't exist.
 */
final class UptimeWebhookController
{
    public function __invoke(Request $request, string $token, Uptime $uptime): Response
    {
        $expected = (string) config('wurk.watchtower.uptime_token');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        [$monitor, $state, $cause, $at] = self::parse($request);
        abort_if($monitor === '' || $state === null, 422, 'Not an uptime alert.');
        $state === 'down' ? $uptime->down($monitor, $cause, $at) : $uptime->up($monitor, $at);

        return response()->noContent();
    }

    /** @return array{0: string, 1: 'down'|'up'|null, 2: string|null, 3: CarbonImmutable} */
    public static function parse(Request $request): array
    {
        // Better Stack: {"data": {"type": "incident", "attributes": {"name", "url", "cause", "started_at", "resolved_at", "status"}}}
        $attributes = $request->input('data.attributes');
        if (is_array($attributes)) {
            $resolved = ($attributes['resolved_at'] ?? null) !== null || strtolower((string) ($attributes['status'] ?? '')) === 'resolved';
            $name = (string) ($attributes['name'] ?? $attributes['url'] ?? '');

            return [$name, $resolved ? 'up' : 'down', is_string($attributes['cause'] ?? null) ? $attributes['cause'] : null,
                self::time($resolved ? ($attributes['resolved_at'] ?? null) : ($attributes['started_at'] ?? null))];
        }
        // UptimeRobot: monitorFriendlyName, alertType (1 = down, 2 = up), alertDetails, alertDateTime (unix seconds)
        if ($request->has('alertType')) {
            $type = (string) $request->input('alertType');

            return [(string) $request->input('monitorFriendlyName', $request->input('monitorURL', '')), $type === '1' ? 'down' : ($type === '2' ? 'up' : null),
                $request->filled('alertDetails') ? (string) $request->input('alertDetails') : null, self::time($request->input('alertDateTime'))];
        }
        $status = strtolower((string) $request->input('status'));

        return [(string) $request->input('monitor', ''), in_array($status, ['down', 'up'], true) ? $status : null,
            $request->filled('cause') ? (string) $request->input('cause') : null, self::time($request->input('at'))];
    }

    private static function time(mixed $value): CarbonImmutable
    {
        try {
            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp((int) $value)->setTimezone((string) config('app.timezone'));
            }
            if (is_string($value) && $value !== '') {
                $t = CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));

                return $t->greaterThan(now()->addMinutes(5)) ? CarbonImmutable::now() : $t; // never in the future
            }
        } catch (Throwable) {
            // fall through
        }

        return CarbonImmutable::now();
    }
}
