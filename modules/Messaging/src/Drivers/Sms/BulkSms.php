<?php

declare(strict_types=1);

namespace Wurk\Messaging\Drivers\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\SmsDriver;

/** BulkSMS.com JSON REST API (api.bulksms.com/v1). */
final class BulkSms implements SmsDriver
{
    public const ENDPOINT = 'https://api.bulksms.com/v1/messages';

    public static function fields(): array
    {
        return ['token_id' => ['label' => 'Token ID', 'secret' => false], 'token_secret' => ['label' => 'Token secret', 'secret' => true]];
    }

    public function send(string $to, string $text, array $credentials, array $settings): SendResult
    {
        try {
            $response = Http::timeout(10)->acceptJson()
                ->withBasicAuth($credentials['token_id'] ?? '', $credentials['token_secret'] ?? '')
                ->post(self::ENDPOINT, ['to' => $to, 'body' => $text, 'encoding' => 'UNICODE']);
        } catch (ConnectionException $e) {
            return SendResult::failed('BulkSMS unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? SendResult::sent((string) ($response->json('0.id') ?? ''))
            : SendResult::failed('BulkSMS: '.($response->json('detail') ?? $response->json('title') ?? 'HTTP '.$response->status()));
    }
}
