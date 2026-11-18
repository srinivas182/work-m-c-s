<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Drivers\WhatsApp\MetaCloudWhatsApp;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Sends from the business's own number with its own token (Sprint 21.2). Each message is charged in credits first and
 * refunded if Meta refuses it — the same rule as every other message WURK sends.
 */
final readonly class WhatsAppBusinessService implements WhatsAppBusiness
{
    public function __construct(private TenantContext $context, private CreditWallet $wallet, private ProviderCredentials $providers) {}

    public function connected(): bool
    {
        return $this->connection() !== null;
    }

    public function sendText(string $to, string $text): SendResult
    {
        return $this->send($to, ['type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4096), 'preview_url' => false]], 'Reply');
    }

    public function sendMedia(string $to, string $kind, string $contents, string $mime, string $filename, string $caption = ''): SendResult
    {
        $c = $this->connection() ?? throw new \RuntimeException('WhatsApp isn’t connected.');
        try {
            $upload = Http::withToken($c->token)->timeout(30)->attach('file', $contents, $filename, ['Content-Type' => $mime])
                ->post($this->url($c->phone_number_id.'/media'), ['messaging_product' => 'whatsapp', 'type' => $mime]);
        } catch (ConnectionException) {
            return SendResult::failed('WhatsApp couldn’t be reached. Try again in a moment.');
        }
        if (! $upload->successful() || ! is_string($upload->json('id'))) {
            return SendResult::failed(self::error($upload));
        }
        $media = ['id' => $upload->json('id')] + ($caption !== '' ? ['caption' => mb_substr($caption, 0, 1024)] : []) + ($kind === 'document' ? ['filename' => $filename] : []);

        return $this->send($to, ['type' => $kind, $kind => $media], $kind === 'image' ? 'Photo' : 'Document');
    }

    public function followUpReady(): bool
    {
        return $this->connection()?->follow_up_status === 'APPROVED';
    }

    public function sendFollowUp(string $to, string $name): SendResult
    {
        if (! $this->followUpReady()) {
            return SendResult::failed('Meta hasn’t approved your follow-up message yet. Check Settings → WhatsApp.');
        }
        $parameters = array_map(fn (string $p) => ['type' => 'text', 'text' => $p], $this->followUpParameters($name));

        return $this->send($to, ['type' => 'template', 'template' => ['name' => WhatsAppGraph::FOLLOW_UP,
            'language' => ['code' => WhatsAppGraph::FOLLOW_UP_LANGUAGE], 'components' => [['type' => 'body', 'parameters' => $parameters]]]], 'Follow-up');
    }

    public function followUpText(string $name): string
    {
        [$customer, $business] = $this->followUpParameters($name);

        return str_replace(['{{1}}', '{{2}}'], [$customer, $business], WhatsAppGraph::FOLLOW_UP_BODY);
    }

    /** @return array{string, string} */
    private function followUpParameters(string $name): array
    {
        $first = trim((string) strtok(trim($name), ' '));
        $business = $this->context->has() ? $this->context->current()->displayName() : '';

        return [mb_substr($first !== '' ? $first : 'there', 0, 40), mb_substr($business !== '' ? $business : 'us', 0, 60)];
    }

    public function media(string $mediaId): ?array
    {
        $c = $this->connection();
        if ($c === null) {
            return null;
        }
        try {
            $info = Http::withToken($c->token)->timeout(15)->get($this->url($mediaId));
            $url = $info->json('url');
            // The file itself must come from Meta's own media servers, with the business's token.
            if (! $info->successful() || ! is_string($url) || ! self::metaHost($url)) {
                return null;
            }
            $file = Http::withToken($c->token)->timeout(60)->withOptions(['allow_redirects' => false])->get($url);
        } catch (Throwable) {
            return null;
        }

        return $file->successful() ? ['contents' => $file->body(), 'mime' => (string) ($info->json('mime_type') ?? $file->header('Content-Type'))] : null;
    }

    /** @param  array<string, mixed>  $message */
    private function send(string $to, array $message, string $what): SendResult
    {
        $c = $this->connection();
        if ($c === null) {
            return SendResult::failed('Connect your WhatsApp number first (Settings → WhatsApp).');
        }
        $key = 'wa:'.Str::ulid();
        try {
            $this->wallet->charge('whatsapp', $key, $what.' on WhatsApp');
        } catch (InsufficientCredits $e) {
            return SendResult::failed($e->getMessage());
        }
        try {
            $response = Http::withToken($c->token)->timeout(15)->post($this->url($c->phone_number_id.'/messages'),
                ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => preg_replace('/\D/', '', $to)] + $message);
            $result = $response->successful() && is_string($response->json('messages.0.id'))
                ? SendResult::sent((string) $response->json('messages.0.id'))
                : SendResult::failed(self::error($response));
        } catch (ConnectionException) {
            $result = SendResult::failed('WhatsApp couldn’t be reached. Try again in a moment.');
        }
        if (! $result->ok) {
            $this->wallet->refund($key, 'Refund: WhatsApp message not sent');
        }

        return $result;
    }

    private function connection(): ?WhatsAppConnection
    {
        if (! $this->context->has()) {
            return null;
        }

        return WhatsAppConnection::query()->where('tenant_id', $this->context->id())->where('status', 'connected')->first();
    }

    private function url(string $path): string
    {
        $version = (string) (($this->providers->active('whatsapp')['settings']['api_version'] ?? '') ?: MetaCloudWhatsApp::DEFAULT_VERSION);

        return 'https://graph.facebook.com/'.$version.'/'.$path;
    }

    /** Meta's reason in plain words where we know it. */
    private static function error(Response $response): string
    {
        return match ((int) $response->json('error.code')) {
            131047 => 'More than 24 hours have passed since the customer last wrote, so WhatsApp only allows an approved template. Ask them to message you, or call them.',
            131026 => 'This number can’t receive WhatsApp messages.',
            131049, 131048 => 'WhatsApp is limiting messages to this customer for now. Try later.',
            133010 => 'Your WhatsApp number isn’t registered with Meta yet. Check Settings → WhatsApp.',
            default => (string) ($response->json('error.error_data.details') ?? $response->json('error.message') ?? 'WhatsApp refused the message ('.$response->status().').'),
        };
    }

    private static function metaHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https' && ($host === 'lookaside.fbsbx.com' || str_ends_with($host, '.fbsbx.com') || str_ends_with($host, '.fbcdn.net') || str_ends_with($host, '.whatsapp.net'));
    }
}
