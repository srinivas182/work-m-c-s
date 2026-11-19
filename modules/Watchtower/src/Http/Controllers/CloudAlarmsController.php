<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Watchtower\Support\CloudAlarms;

/**
 * POST /_wurk/alarms/{token} (Sprint 17.5): Amazon SNS delivers the cloud's alarms here. The token
 * (WATCHTOWER_ALARM_TOKEN, an infrastructure secret) and SNS's signature must both match; without the token set,
 * the endpoint doesn't exist.
 */
final class CloudAlarmsController
{
    public function __invoke(Request $request, string $token, CloudAlarms $alarms): Response
    {
        $expected = (string) config('wurk.watchtower.alarm_token', '');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $m = json_decode((string) $request->getContent(), true); // SNS posts JSON as text/plain
        abort_unless(is_array($m) && $alarms->verified($m), 403);

        return match ($m['Type']) {
            'SubscriptionConfirmation' => $alarms->confirm((string) ($m['SubscribeURL'] ?? '')) ? response()->noContent() : abort(422),
            'Notification' => response($alarms->changed((string) $m['Message']), 200, ['Content-Type' => 'text/plain']),
            default => response()->noContent(),
        };
    }
}
