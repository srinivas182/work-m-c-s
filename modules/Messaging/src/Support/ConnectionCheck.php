<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Models\ProviderSetting;

/**
 * "Test connection" for providers that don't send messages (Sprint 19.6): a read-only request with the saved keys,
 * so HQ knows a key works before switching it on. Nothing is charged or changed at the provider.
 */
final class ConnectionCheck
{
    /** Channels with a check here; message channels send a real test instead. */
    public const CHANNELS = ['ai.anthropic', 'ai.openai', 'ai.google', 'payments.paystack', 'meetings.google', 'meetings.zoom', 'places.google'];

    public function check(string $channel, ProviderSetting $setting): SendResult
    {
        $c = $setting->credentials ?? [];
        try {
            return match ($channel) {
                'ai.anthropic' => $this->keyResult('Anthropic', Http::timeout(15)->withHeaders(['x-api-key' => $c['api_key'] ?? '', 'anthropic-version' => '2023-06-01'])->get('https://api.anthropic.com/v1/models')),
                'ai.openai' => $this->keyResult('OpenAI', Http::timeout(15)->withToken($c['api_key'] ?? '')->get('https://api.openai.com/v1/models')),
                'ai.google' => $this->keyResult('Google', Http::timeout(15)->withHeaders(['x-goog-api-key' => $c['api_key'] ?? ''])->get('https://generativelanguage.googleapis.com/v1beta/models')),
                'places.google' => $this->keyResult('Google Places', Http::timeout(15)->withHeaders(['X-Goog-Api-Key' => $c['api_key'] ?? '', 'X-Goog-FieldMask' => 'places.id'])
                    ->post('https://places.googleapis.com/v1/places:searchText', ['textQuery' => 'Johannesburg', 'maxResultCount' => 1])),
                'payments.paystack' => $this->keyResult('Paystack', Http::timeout(15)->withToken($c['secret_key'] ?? '')->get('https://api.paystack.co/balance')),
                // OAuth apps can't be fully tried without someone signing in; a made-up code tells a wrong client
                // ("invalid_client") from right credentials with a bad code ("invalid_grant").
                'meetings.google' => $this->oauthResult('Google', Http::timeout(15)->asForm()->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $c['client_id'] ?? '', 'client_secret' => $c['client_secret'] ?? '', 'grant_type' => 'authorization_code',
                    'code' => 'wurk-connection-test', 'redirect_uri' => 'https://'.config('wurk.domains.app').'/oauth/test',
                ])),
                'meetings.zoom' => $this->oauthResult('Zoom', Http::timeout(15)->asForm()->withBasicAuth($c['client_id'] ?? '', $c['client_secret'] ?? '')
                    ->post('https://zoom.us/oauth/token', ['grant_type' => 'authorization_code', 'code' => 'wurk-connection-test', 'redirect_uri' => 'https://'.config('wurk.domains.app').'/oauth/test'])),
                default => SendResult::failed('This provider has no connection test.'),
            };
        } catch (Throwable) {
            return SendResult::failed('Couldn’t reach the provider. Check the server’s internet connection and try again.');
        }
    }

    private function keyResult(string $name, Response $r): SendResult
    {
        return match (true) {
            $r->successful() => SendResult::sent(null),
            in_array($r->status(), [401, 403], true) => SendResult::failed("{$name} refused the key. Check it was copied in full."),
            default => SendResult::failed("{$name} didn’t answer as expected (HTTP {$r->status()}). Try again shortly."),
        };
    }

    private function oauthResult(string $name, Response $r): SendResult
    {
        $error = (string) ($r->json('error') ?? '');

        return match (true) {
            $error === 'invalid_client', $r->status() === 401 && $error !== 'invalid_grant' => SendResult::failed("{$name} didn’t accept the client ID and secret."),
            in_array($error, ['invalid_grant', 'invalid_request', 'redirect_uri_mismatch'], true) => SendResult::sent(null),
            default => SendResult::failed("{$name} didn’t answer as expected (HTTP {$r->status()}). Try again shortly."),
        };
    }
}
