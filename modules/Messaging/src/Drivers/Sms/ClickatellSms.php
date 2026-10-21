<?php

declare(strict_types=1);

namespace Wurk\Messaging\Drivers\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\SmsDriver;

/** Clickatell One API (platform.clickatell.com). */
final class ClickatellSms implements SmsDriver
{
    public const ENDPOINT = 'https://platform.clickatell.com/messages';

    public static function fields(): array
    {
        return ['api_key' => ['label' => 'API key', 'secret' => true]];
    }

    public function send(string $to, string $text, array $credentials, array $settings): SendResult
    {
        try {
            $response = Http::timeout(10)->acceptJson()
                ->withHeaders(['Authorization' => $credentials['api_key'] ?? ''])
                ->post(self::ENDPOINT, ['messages' => [['channel' => 'sms', 'to' => ltrim($to, '+'), 'content' => $text]]]);
        } catch (ConnectionException $e) {
            return SendResult::failed('Clickatell unreachable: '.$e->getMessage());
        }

        $message = $response->json('messages.0');
        if ($response->successful() && is_array($message) && ($message['accepted'] ?? false)) {
            return SendResult::sent((string) ($message['apiMessageId'] ?? ''));
        }

        return SendResult::failed('Clickatell: '.($message['error']['description'] ?? $response->json('error.description') ?? 'HTTP '.$response->status()));
    }
}
