<?php

declare(strict_types=1);

namespace Wurk\Messaging\Drivers\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\SmsDriver;

/** SMSPortal REST v3 (rest.smsportal.com) with Basic authentication on every request. */
final class SmsPortalSms implements SmsDriver
{
    public const ENDPOINT = 'https://rest.smsportal.com/v3/bulkmessages';

    public static function fields(): array
    {
        return ['client_id' => ['label' => 'API client ID', 'secret' => false], 'api_secret' => ['label' => 'API secret', 'secret' => true]];
    }

    public function send(string $to, string $text, array $credentials, array $settings): SendResult
    {
        try {
            $response = Http::timeout(10)->acceptJson()
                ->withBasicAuth($credentials['client_id'] ?? '', $credentials['api_secret'] ?? '')
                ->post(self::ENDPOINT, [
                    'messages' => [['content' => $text, 'destination' => ltrim($to, '+')]],
                    // Test mode validates credentials and the request without sending or charging.
                    'sendOptions' => ['testMode' => ($settings['test_mode'] ?? '') === '1'],
                ]);
        } catch (ConnectionException $e) {
            return SendResult::failed('SMSPortal unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? SendResult::sent((string) ($response->json('eventId') ?? $response->json('EventId') ?? ''))
            : SendResult::failed('SMSPortal: '.($response->json('errorReport.faults.0.errorMessage') ?? 'HTTP '.$response->status()));
    }
}
