<?php

declare(strict_types=1);

namespace Wurk\Messaging\Http\Webhooks;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Messaging\Events\WhatsAppStatusChanged;
use Wurk\Messaging\Events\WurkWhatsAppReceived;
use Wurk\Messaging\Models\MessageDelivery;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\OwnerSummaries;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Messaging\Support\WhatsAppGraph;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Delivery receipts. Both endpoints verify the provider's signature with the
 * stored secret before touching anything; unsigned or forged calls get 403.
 */
final readonly class DeliveryWebhooks
{
    public function __construct(private ProviderSettings $providers, private TenantDirectory $tenants, private TenantContext $context) {}

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
                $value = (array) ($change['value'] ?? []);
                if (($change['field'] ?? '') === 'message_template_status_update') { // Meta reviewed one of WURK's templates (21.5)
                    $this->templateReviewed((string) ($entry['id'] ?? ''), $value);

                    continue;
                }
                // A business's own number (Sprint 21.2): hand messages and statuses to that business, inside its context.
                $connection = WhatsAppConnection::query()->where('phone_number_id', (string) ($value['metadata']['phone_number_id'] ?? ''))
                    ->where('status', 'connected')->first();
                $tenant = $connection !== null ? $this->tenants->find($connection->tenant_id) : null;
                if ($tenant !== null) {
                    $this->context->run($tenant, fn () => $this->business($tenant->tenantId(), $value));

                    continue;
                }
                // Messages to WURK's own number: owners answering their evening summary (21.6), STOP (23.5b), and
                // questions and voice notes to the help line (25.6), and owners' instructions (26.1).
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $text = (string) ($m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? '');
                    $from = (string) preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                    $voice = ($m['type'] ?? '') === 'audio' ? (string) ($m['audio']['id'] ?? '') : '';
                    if ($from === '' || ($text === '' && $voice === '')) {
                        continue;
                    }
                    $answered = false;
                    $button = isset($m['interactive']['button_reply']['id']) ? (string) $m['interactive']['button_reply']['id'] : null;
                    // A tap on "Yes" to an owner's instruction (26.1, ids "act:…") is never a summary reply.
                    if ($text !== '' && ! str_starts_with((string) $button, 'act:') && strtoupper(trim($text)) !== 'STOP'
                        && ($answer = app(OwnerSummaries::class)->reply($from, $text)) !== null) {
                        app(OwnerSummaries::class)->answer($from, $answer);
                        $answered = true;
                    }
                    event(new WurkWhatsAppReceived($from, $text, $voice !== '' ? $voice : null, $voice !== '' ? (string) ($m['audio']['mime_type'] ?? 'audio/ogg') : null,
                        $answered, (string) ($m['id'] ?? ''), $button));
                }
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->update((string) ($status['id'] ?? ''), match ($status['status'] ?? '') {
                        'delivered', 'read' => 'delivered', 'failed' => 'failed', default => null,
                    }, $status['errors'][0]['title'] ?? null);
                }
            }
        }

        return new Response('', 200);
    }

    /** @param  array<string, mixed>  $value */
    private function templateReviewed(string $wabaId, array $value): void
    {
        $name = (string) ($value['message_template_name'] ?? '');
        if (! isset(WhatsAppGraph::TEMPLATES[$name]) || ($value['message_template_language'] ?? WhatsAppGraph::FOLLOW_UP_LANGUAGE) !== WhatsAppGraph::FOLLOW_UP_LANGUAGE) {
            return;
        }
        foreach (WhatsAppConnection::query()->where('waba_id', $wabaId)->where('status', 'connected')->get() as $c) {
            $c->update(['templates' => [...(array) $c->templates, $name => strtoupper((string) ($value['event'] ?? 'PENDING'))]]);
        }
    }

    /** @param  array<string, mixed>  $value */
    private function business(string $tenantId, array $value): void
    {
        $names = [];
        foreach ((array) ($value['contacts'] ?? []) as $contact) {
            $names[(string) ($contact['wa_id'] ?? '')] = $contact['profile']['name'] ?? null;
        }
        foreach ((array) ($value['messages'] ?? []) as $m) {
            if (! is_array($m) || ! isset($m['id'], $m['from'])) {
                continue;
            }
            $type = (string) ($m['type'] ?? 'unsupported');
            $part = is_array($m[$type] ?? null) ? $m[$type] : [];
            $interactive = (array) ($part['button_reply'] ?? $part['list_reply'] ?? []);
            [$text, $extra] = match ($type) {
                'text' => [(string) ($part['body'] ?? ''), []],
                'image', 'video', 'document' => [(string) ($part['caption'] ?? ''), []],
                // A tapped list row reads as its title and description ("10:00 — Saturday 22 November"), as the customer saw it.
                'interactive' => [trim(($interactive['title'] ?? '').(($interactive['description'] ?? '') !== '' ? ' — '.$interactive['description'] : '')), ['reply_id' => $interactive['id'] ?? null]],
                'button' => [(string) ($part['text'] ?? ''), ['reply_id' => $part['payload'] ?? null]],
                'location' => [trim(($part['name'] ?? '').' '.($part['address'] ?? '')), ['latitude' => $part['latitude'] ?? null, 'longitude' => $part['longitude'] ?? null]],
                'reaction' => [(string) ($part['emoji'] ?? ''), ['reacted_to' => $part['message_id'] ?? null]],
                default => ['', []],
            };
            event(new WhatsAppReceived($tenantId, preg_replace('/\D/', '', (string) $m['from']) ?? '', $names[(string) $m['from']] ?? null, (string) $m['id'], $type,
                mb_substr($text, 0, 4096), isset($part['id']) && in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true) ? (string) $part['id'] : null,
                $part['mime_type'] ?? null, $part['filename'] ?? null,
                (new DateTimeImmutable)->setTimestamp((int) ($m['timestamp'] ?? time())), array_filter($extra, fn ($v) => $v !== null) + (isset($m['context']['id']) ? ['in_reply_to' => (string) $m['context']['id']] : [])));
        }
        foreach ((array) ($value['statuses'] ?? []) as $status) {
            if (is_array($status) && isset($status['id'], $status['status'])) {
                event(new WhatsAppStatusChanged($tenantId, (string) $status['id'], (string) $status['status'], $status['errors'][0]['title'] ?? null));
            }
        }
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
