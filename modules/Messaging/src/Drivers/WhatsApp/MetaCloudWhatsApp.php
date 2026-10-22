<?php

declare(strict_types=1);

namespace Wurk\Messaging\Drivers\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Contracts\SendResult;

/**
 * WhatsApp Business Platform, Cloud API (graph.facebook.com). Business-initiated
 * messages must use templates approved by Meta; the API version is a setting so
 * it can follow Meta's deprecation schedule without a release.
 */
final class MetaCloudWhatsApp
{
    public const ENDPOINT = 'https://graph.facebook.com/%s/%s/messages';

    public const DEFAULT_VERSION = 'v23.0';

    /** @return array<string, array{label: string, secret: bool}> */
    public static function fields(): array
    {
        return ['access_token' => ['label' => 'Permanent access token', 'secret' => true], 'app_secret' => ['label' => 'App secret (verifies delivery webhooks)', 'secret' => true]];
    }

    /**
     * @param  list<string>  $parameters
     * @param  array<string, string>  $credentials
     * @param  array<string, string>  $settings
     */
    public function sendTemplate(string $to, string $template, array $parameters, string $language, array $credentials, array $settings, bool $authentication = false): SendResult
    {
        $body = array_map(fn (string $p) => ['type' => 'text', 'text' => $p], $parameters);
        $components = [['type' => 'body', 'parameters' => $body]];
        if ($authentication && $parameters !== []) {
            // Authentication templates carry the code again on the copy-code button.
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $parameters[0]]]];
        }

        try {
            $response = Http::timeout(10)->acceptJson()->withToken($credentials['access_token'] ?? '')
                ->post(sprintf(self::ENDPOINT, $settings['api_version'] ?? self::DEFAULT_VERSION, $settings['phone_number_id'] ?? ''), [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($to, '+'),
                    'type' => 'template',
                    'template' => ['name' => $template, 'language' => ['code' => $language], 'components' => $components],
                ]);
        } catch (ConnectionException $e) {
            return SendResult::failed('WhatsApp unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? SendResult::sent((string) ($response->json('messages.0.id') ?? ''))
            : SendResult::failed('WhatsApp: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
    }
}
