<?php

declare(strict_types=1);

namespace Wurk\Messaging\Drivers\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\SmsDriver;

/** Brevo transactional SMS (POST /v3/transactionalSMS/send; /sms was deprecated in May 2025). */
final class BrevoSms implements SmsDriver
{
    public const ENDPOINT = 'https://api.brevo.com/v3/transactionalSMS/send';

    public static function fields(): array
    {
        return ['api_key' => ['label' => 'API key', 'secret' => true]];
    }

    public function send(string $to, string $text, array $credentials, array $settings): SendResult
    {
        try {
            $response = Http::timeout(10)->acceptJson()
                ->withHeaders(['api-key' => $credentials['api_key'] ?? ''])
                ->post(self::ENDPOINT, [
                    'sender' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $settings['sender'] ?? 'WURK') ?: 'WURK', 0, 11),
                    'recipient' => ltrim($to, '+'),
                    'content' => $text,
                    'type' => 'transactional',
                    'unicodeEnabled' => true,
                ]);
        } catch (ConnectionException $e) {
            return SendResult::failed('Brevo unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? SendResult::sent((string) ($response->json('messageId') ?? ''))
            : SendResult::failed('Brevo: '.($response->json('message') ?? 'HTTP '.$response->status()));
    }
}
