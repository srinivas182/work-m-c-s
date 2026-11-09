<?php

declare(strict_types=1);

namespace Wurk\Website\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Website analytics (Sprint 15.2), cookie-free in the manner of Plausible: a visitor is a one-way hash of a random daily
 * secret (discarded after two days), the business, the IP address and the browser — counted once a day, never stored as
 * an IP, never followed across days. Where they came from is the referring site or a campaign's utm_source.
 */
final readonly class SiteVisits
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview|monitor|curl|wget|python|headless|lighthouse/i';

    public function __construct(private TenantContext $context) {}

    public function record(Request $request, string $path): void
    {
        if (! $this->context->has() || ! $request->isMethod('GET') || $this->isBot($request)) {
            return;
        }
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $day = now($this->context->current()->timezone())->toDateString();
        $path = mb_substr('/'.ltrim($path, '/'), 0, 200);
        $updated = $db->table('site_page_views')->where('tenant_id', $t)->where('day', $day)->where('path', $path)->increment('views');
        if ($updated === 0) {
            $db->table('site_page_views')->insertOrIgnore(['tenant_id' => $t, 'day' => $day, 'path' => $path, 'views' => 1]);
        }
        $db->table('site_visitors')->insertOrIgnore(['tenant_id' => $t, 'day' => $day, 'hash' => $this->hash($request, $day), 'source' => $this->source($request)]); // first source of the day wins
    }

    /** An order or booking made on the site today: credit where that visitor came from. */
    public function convert(Request $request, string $sourceType, string $sourceId): void
    {
        if (! $this->context->has() || ! in_array($sourceType, ['order', 'booking', 'class'], true)) {
            return;
        }
        $db = DB::connection($this->context->connectionName());
        $day = now($this->context->current()->timezone())->toDateString();
        $source = $db->table('site_visitors')->where('tenant_id', $this->context->id())->where('day', $day)->where('hash', $this->hash($request, $day))->value('source');
        if ($source !== null) {
            $db->table('site_conversions')->insertOrIgnore(['tenant_id' => $this->context->id(), 'day' => $day, 'source' => (string) $source, 'source_type' => $sourceType, 'source_id' => $sourceId]);
        }
    }

    public function isBot(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        return $ua === '' || preg_match(self::BOTS, $ua) === 1 || in_array(strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose'))), ['prefetch', 'prerender'], true);
    }

    public function source(Request $request): string
    {
        $utm = trim((string) $request->query('utm_source', ''));
        if ($utm !== '') {
            return mb_substr(ucfirst(strtolower($utm)), 0, 60);
        }
        $host = strtolower((string) parse_url((string) $request->headers->get('referer', ''), PHP_URL_HOST));
        if ($host === '' || $host === strtolower($request->getHost())) {
            return 'Direct';
        }
        foreach (['google' => 'Google', 'bing' => 'Bing', 'facebook' => 'Facebook', 'fb.' => 'Facebook', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'wa.me' => 'WhatsApp',
            'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 't.co' => 'X (Twitter)', 'twitter' => 'X (Twitter)', 'youtube' => 'YouTube'] as $needle => $name) {
            if (str_contains($host, $needle)) {
                return $name;
            }
        }

        return mb_substr((string) preg_replace('/^www\./', '', $host), 0, 60);
    }

    private function hash(Request $request, string $day): string
    {
        $salt = (string) Cache::remember('site-visit-salt:'.$day, now()->addDays(2), fn () => Str::random(40)); // random, then forgotten

        return substr(hash('sha256', $salt.'|'.$this->context->id().'|'.$request->ip().'|'.$request->userAgent()), 0, 32);
    }
}
