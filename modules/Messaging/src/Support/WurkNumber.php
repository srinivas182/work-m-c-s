<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Messaging\Contracts\WurkWhatsApp;
use Wurk\Messaging\Drivers\WhatsApp\MetaCloudWhatsApp;
use Wurk\Messaging\Models\ProviderSetting;

/** WURK's own WhatsApp number, with the credentials HQ keeps in Admin → Providers → WhatsApp. */
final readonly class WurkNumber implements WurkWhatsApp
{
    public function __construct(private ProviderSettings $providers, private MetaCloudWhatsApp $driver) {}

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    public function sendText(string $to, string $text): bool
    {
        $p = $this->provider();

        return $p !== null && $this->driver->sendText($to, $text, (array) $p['credentials'], (array) $p['settings'])->ok;
    }

    public function sendTemplate(string $to, string $template, array $parameters, string $language = 'en'): bool
    {
        $p = $this->provider();

        return $p !== null && $this->driver->sendTemplate($to, $template, $parameters, $language, (array) $p['credentials'], (array) $p['settings'])->ok;
    }

    public function media(string $mediaId): ?array
    {
        $p = $this->provider();
        $token = (string) ($p['credentials']['access_token'] ?? '');
        if ($p === null || $token === '' || preg_match('/^\d{1,40}$/', $mediaId) !== 1) {
            return null;
        }
        $version = (string) (($p['settings']['api_version'] ?? '') ?: MetaCloudWhatsApp::DEFAULT_VERSION);
        try {
            $info = Http::withToken($token)->timeout(15)->get('https://graph.facebook.com/'.$version.'/'.$mediaId);
            $url = $info->json('url');
            // The file itself must come from Meta's own media servers.
            if (! $info->successful() || ! is_string($url) || ! WhatsAppBusinessService::isMetaHost($url)) {
                return null;
            }
            $file = Http::withToken($token)->timeout(60)->withOptions(['allow_redirects' => false])->get($url);
        } catch (Throwable) {
            return null;
        }

        return $file->successful() ? ['contents' => $file->body(), 'mime' => (string) ($info->json('mime_type') ?? $file->header('Content-Type'))] : null;
    }

    private function provider(): ?ProviderSetting
    {
        $p = $this->providers->active('whatsapp');

        return $p !== null && ($p['settings']['phone_number_id'] ?? '') !== '' ? $p : null;
    }
}
