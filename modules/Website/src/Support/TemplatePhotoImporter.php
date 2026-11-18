<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\TemplateAsset;

/**
 * Fills a design's photo slot (Sprint 20.3): from a file HQ uploads, or from a link to a photo on Nappy, Pexels or
 * Unsplash, which the server downloads. Only those sites are ever contacted (no other address, including through a
 * redirect), and the photo goes through the same safe pipeline as every upload (type check, re-encode, no metadata).
 */
final readonly class TemplatePhotoImporter
{
    /** Hosts photos may come from — the photo pages and the image servers behind them. */
    public const HOSTS = [
        'nappy.co', 'www.nappy.co', 'images.nappy.co',
        'pexels.com', 'www.pexels.com', 'images.pexels.com',
        'unsplash.com', 'images.unsplash.com', 'plus.unsplash.com',
    ];

    /** Licences HQ records (what the photo may be used under). */
    public const LICENCES = ['Nappy (CC0)', 'Pexels licence', 'Unsplash licence', 'Paid licence', 'Own photo', 'WURK AI'];

    private const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(private SiteAssets $assets, private AuditLog $audit) {}

    /**
     * @param  array{photographer?: string|null, licence: string, source_url?: string|null}  $credit
     */
    public function fromUpload(SiteTemplate $template, string $slot, UploadedFile $file, array $credit, ?string $by): TemplateAsset
    {
        return $this->store($template, $slot, $file, $credit, $by);
    }

    /**
     * @param  array{photographer?: string|null, licence: string}  $credit
     */
    public function fromLink(SiteTemplate $template, string $slot, string $link, array $credit, ?string $by): TemplateAsset
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['link' => $m]);
        if (! self::allowed($link)) {
            $fail('Paste a link to a photo on Nappy, Pexels or Unsplash.');
        }
        $response = $this->get($link);
        $type = strtolower((string) $response->header('Content-Type'));
        $image = $link;
        if (str_starts_with($type, 'text/html')) {
            // A photo's page: its preview image is the photo (og:image), on the same site's image server.
            $image = html_entity_decode((string) (self::meta($response->body(), 'og:image') ?? ''));
            if ($image === '' || ! self::allowed($image)) {
                $fail('That page has no photo we can use. Open the photo itself and copy its link.');
            }
            $credit['photographer'] = ($credit['photographer'] ?? '') !== '' ? $credit['photographer'] : self::photographer($response->body());
            $response = $this->get($image);
            $type = strtolower((string) $response->header('Content-Type'));
        }
        if (! in_array(strtok($type, ';'), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $fail('That link isn’t a JPEG, PNG or WebP photo.');
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            $fail('That photo is larger than 15 MB. Choose a smaller size on the site.');
        }
        $path = tempnam(sys_get_temp_dir(), 'wurk-template-photo-');
        try {
            file_put_contents((string) $path, $body);
            $file = new UploadedFile((string) $path, $slot.'.jpg', null, null, true);

            return $this->store($template, $slot, $file, [...$credit, 'source_url' => mb_substr($link, 0, 500)], $by);
        } finally {
            @unlink((string) $path);
        }
    }

    public static function allowed(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['port'])
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true);
    }

    private function get(string $url): Response
    {
        $response = Http::timeout(30)->withHeaders(['User-Agent' => 'WURK template photos (+https://wurk.co.za)'])
            ->withOptions([
                'allow_redirects' => [
                    'max' => 3, 'protocols' => ['https'],
                    // Follow a redirect only to another allowed address.
                    'on_redirect' => function (RequestInterface $request, $response, UriInterface $uri): void {
                        if (! self::allowed((string) $uri)) {
                            throw ValidationException::withMessages(['link' => 'That link leads away from Nappy, Pexels or Unsplash.']);
                        }
                    },
                ],
            ])->get($url);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['link' => 'That link didn’t open (the site answered '.$response->status().').']);
        }

        return $response;
    }

    /**
     * @param  array{photographer?: string|null, licence: string, source_url?: string|null}  $credit
     */
    private function store(SiteTemplate $template, string $slot, UploadedFile $file, array $credit, ?string $by): TemplateAsset
    {
        $id = (string) Str::ulid();
        $result = $this->assets->process($file, 'templates/'.$template->key, $id);
        $asset = TemplateAsset::query()->create([
            'id' => $id, 'template_id' => $template->id, 'name' => $slot, 'slot' => $slot,
            'width' => $result['width'], 'height' => $result['height'], 'bytes' => $result['bytes'], 'variants' => $result['variants'],
            'source_url' => $credit['source_url'] ?? null, 'photographer' => ($p = trim((string) ($credit['photographer'] ?? ''))) === '' ? null : mb_substr($p, 0, 120),
            'licence' => $credit['licence'], 'ai_generated' => $credit['licence'] === 'WURK AI', 'added_by' => $by, 'created_at' => now(),
        ]);
        $this->audit->record('website.template_photo_set', 'site_template', $template->id, ['slot' => $slot, 'licence' => $credit['licence'], 'source' => $asset->source_url]);

        return $asset;
    }

    private static function meta(string $html, string $property): ?string
    {
        foreach (['/<meta[^>]+property=["\']'.preg_quote($property, '/').'["\'][^>]*content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']'.preg_quote($property, '/').'["\']/i'] as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** The photographer's name, when the page says it ("Photo by …"). */
    private static function photographer(string $html): ?string
    {
        $title = html_entity_decode((string) (self::meta($html, 'og:title') ?? ''));
        if (preg_match('/(?:photo|image) by ([^|–—\-]+)/i', $title, $m) === 1) {
            return trim($m[1]);
        }
        $author = self::metaName($html, 'author');

        return $author !== null ? trim(html_entity_decode($author)) : null;
    }

    private static function metaName(string $html, string $name): ?string
    {
        return preg_match('/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m) === 1 ? $m[1] : null;
    }
}
