<?php

declare(strict_types=1);

namespace Wurk\Messaging\Drivers\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\SmsDriver;

/** Twilio Programmable Messaging (2010-04-01 API). */
final class TwilioSms implements SmsDriver
{
    public const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    public static function fields(): array
    {
        return ['account_sid' => ['label' => 'Account SID', 'secret' => false], 'auth_token' => ['label' => 'Auth token', 'secret' => true]];
    }

    public function send(string $to, string $text, array $credentials, array $settings): SendResult
    {
        $sid = $credentials['account_sid'] ?? '';
        $payload = ['To' => $to, 'Body' => $text];
        if (($settings['messaging_service_sid'] ?? '') !== '') {
            $payload['MessagingServiceSid'] = $settings['messaging_service_sid'];
        } else {
            $payload['From'] = $settings['sender'] ?? '';
        }
        if (($settings['status_callback'] ?? '') !== '') {
            $payload['StatusCallback'] = $settings['status_callback'];
        }

        try {
            $response = Http::timeout(10)->acceptJson()->asForm()
                ->withBasicAuth($sid, $credentials['auth_token'] ?? '')
                ->post(sprintf(self::ENDPOINT, rawurlencode($sid)), $payload);
        } catch (ConnectionException $e) {
            return SendResult::failed('Twilio unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? SendResult::sent((string) ($response->json('sid') ?? ''))
            : SendResult::failed('Twilio: '.($response->json('message') ?? 'HTTP '.$response->status()));
    }
}
