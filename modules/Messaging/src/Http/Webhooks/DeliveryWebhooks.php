<?php

declare(strict_types=1);

namespace Wurk\Messaging\Http\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Messaging\Models\MessageDelivery;
use Wurk\Messaging\Support\ProviderSettings;

/**
 * Delivery receipts. Both endpoints verify the provider's signature with the
 * stored secret before touching anything; unsigned or forged calls get 403.
 */
final readonly class DeliveryWebhooks
{
    public function __construct(private ProviderSettings $providers) {}

    /** Twilio status callback: X-Twilio-Signature = base64(HMAC-SHA1(url + sorted params, auth token)). */
    public function twilio(Request $request): Response
    {
        $setting = $this->providers->active('sms');
        $token = $setting?->driver === 'twilio' ? ($setting->credentials['auth_token'] ?? '') : '';
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $key => $value) {
            $data .= $key.(is_scalar($value) ? $value : '');
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));

        if ($token === '' || ! hash_equals($expected, (string) $request->header('X-Twilio-Signature'))) {
            return new Response('', 403);
        }

        $status = (string) $request->input('MessageStatus');
        $this->update((string) $request->input('MessageSid'), match ($status) {
            'delivered' => 'delivered', 'failed', 'undelivered' => 'failed', default => null,
        }, $request->input('ErrorCode') ? 'Carrier error '.$request->input('ErrorCode') : null);

        return new Response('', 204);
    }

    /** Meta subscription check (GET) — echoes the challenge when the verify token matches. */
    public function metaVerify(Request $request): Response
    {
        $token = $this->providers->active('whatsapp')?->settings['verify_token'] ?? null;

        return $token !== null && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))
            ? new Response((string) $request->query('hub_challenge'), 200)
            : new Response('', 403);
    }

    /** Meta status events: X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(raw body, app secret). */
    public function meta(Request $request): Response
    {
        $secret = $this->providers->active('whatsapp')?->credentials['app_secret'] ?? '';
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
            return new Response('', 403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $this->update((string) ($status['id'] ?? ''), match ($status['status'] ?? '') {
                        'delivered', 'read' => 'delivered', 'failed' => 'failed', default => null,
                    }, $status['errors'][0]['title'] ?? null);
                }
            }
        }

        return new Response('', 200);
    }

    private function update(string $providerId, ?string $status, ?string $error): void
    {
        if ($providerId === '' || $status === null) {
            return;
        }

        MessageDelivery::query()->where('provider_message_id', $providerId)->update(array_filter([
            'status' => $status,
            'delivered_at' => $status === 'delivered' ? now() : null,
            'error' => $error,
        ]));
    }
}
