<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\SetupInterview;

/**
 * Set up from what the business already has online (Sprint 23.2): a Google listing (through the Places API, with WURK's
 * key), or the public preview and text of a Facebook page, Instagram profile or website. Only public information is
 * read; nothing is copied from reviews. What's found fills the setup brief as answers the owner then checks and can
 * change (23.1), and nothing is set up until they confirm.
 *
 * Pages are fetched safely: https only, public addresses only (checked again on every redirect), 1 MB at most.
 */
final class OnlineImport
{
    public const MAX_BYTES = 1_048_576;

    /** @var (\Closure(string): list<string>)|null Tests: resolve a host name without DNS. */
    public static ?\Closure $resolver = null;

    private const GOOGLE_HOSTS = ['maps.app.goo.gl', 'goo.gl', 'g.page', 'www.google.com', 'google.com', 'maps.google.com', 'www.google.co.za', 'google.co.za', 'business.google.com'];

    public function __construct(private readonly TenantContext $context, private readonly ProviderCredentials $providers, private readonly SetupTalk $talk, private readonly AuditLog $audit) {}

    /**
     * Fill the owner's setup from a link (Google listing, Facebook, Instagram, website) or from a business name and area.
     *
     * @return list<string> the questions it answered
     */
    public function import(SetupInterview $i, string $input): array
    {
        $input = trim(mb_substr($input, 0, 500));
        $url = filter_var($input, FILTER_VALIDATE_URL) ? $input : null;
        $host = $url === null ? '' : strtolower((string) parse_url($url, PHP_URL_HOST));
        [$found, $source] = $url === null || in_array($host, self::GOOGLE_HOSTS, true) || str_ends_with($host, '.google.com')
            ? [$this->fromGoogle($url === null ? $input : $this->googleQuery($url)), 'your Google listing']
            : [$this->fromPage($url), $host];
        $answered = [];
        $brief = $i->brief;
        $answers = $i->answers;
        foreach ($found as $key => $part) {
            $brief = [...$brief, ...$part];
            $answers[$key] = "From {$source}";
            $answered[] = $key;
        }
        if ($answered === []) {
            throw ValidationException::withMessages(['link' => 'We couldn’t find your business details there. Answer the questions instead.']);
        }
        $i->update(['brief' => $brief, 'answers' => $answers]);
        $this->audit->record('website.setup_imported', 'setup_interview', $i->id, ['source' => $source, 'answered' => $answered]);

        return $answered;
    }

    /**
     * The Google listing that matches, through the Places API (New).
     *
     * @return array<string, array<string, mixed>> question key => its part of the brief
     */
    private function fromGoogle(string $query): array
    {
        $key = (string) ($this->providers->active('places.google')['credentials']['api_key'] ?? '');
        if ($key === '') {
            throw ValidationException::withMessages(['link' => 'Google listings can’t be read yet. Paste your website or Facebook page instead, or answer the questions.']);
        }
        try {
            $r = Http::timeout(15)->withHeaders(['X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.regularOpeningHours,places.primaryTypeDisplayName,places.editorialSummary,places.addressComponents'])
                ->post('https://places.googleapis.com/v1/places:searchText', ['textQuery' => $query, 'maxResultCount' => 1, 'regionCode' => strtolower($this->context->current()->countryCode())]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['link' => 'Google didn’t answer. Try again in a minute.']);
        }
        $p = $r->successful() ? (array) ($r->json('places.0') ?? []) : [];
        if ($p === []) {
            return [];
        }
        $text = fn ($v) => mb_substr(trim(strip_tags((string) $v)), 0, 200);
        $area = collect((array) ($p['addressComponents'] ?? []))->first(fn ($c) => array_intersect(['sublocality', 'locality'], (array) ($c['types'] ?? [])) !== []);
        $found = [
            'business' => ['name' => $text($p['displayName']['text'] ?? ''), 'what' => $text($p['editorialSummary']['text'] ?? $p['primaryTypeDisplayName']['text'] ?? '')],
            'area' => ['area' => $text($area['longText'] ?? ''), 'address' => $text($p['formattedAddress'] ?? '')],
        ];
        if (($p['nationalPhoneNumber'] ?? '') !== '') {
            $found['contact'] = ['phone' => $text($p['nationalPhoneNumber']), 'whatsapp' => '', 'email' => ''];
        }
        $hours = self::hoursFromPeriods((array) ($p['regularOpeningHours']['periods'] ?? []));
        if ($hours !== null) {
            $found['hours'] = ['hours' => $hours];
        }

        return array_filter($found, fn ($part) => array_filter($part) !== []);
    }

    /**
     * Seven rows, Monday first, from Google's periods (day 0 = Sunday); null when there are none.
     *
     * @param  array<mixed>  $periods
     * @return list<array{opens: string, closes: string}>|null
     */
    public static function hoursFromPeriods(array $periods): ?array
    {
        $rows = array_fill(0, 7, ['opens' => '', 'closes' => '']);
        $any = false;
        foreach ($periods as $p) {
            $p = (array) $p;
            $open = (array) ($p['open'] ?? []);
            $close = (array) ($p['close'] ?? []);
            if (! isset($open['day'], $open['hour'], $close['hour'])) {
                continue;
            }
            $row = ((int) $open['day'] + 6) % 7; // Sunday 0 → row 6
            $rows[$row] = ['opens' => sprintf('%02d:%02d', $open['hour'], $open['minute'] ?? 0), 'closes' => sprintf('%02d:%02d', $close['hour'], $close['minute'] ?? 0)];
            $any = true;
        }

        return $any ? array_values($rows) : null;
    }

    /** The business name in a Google Maps link (short links are followed, only to Google). */
    private function googleQuery(string $url): string
    {
        $final = $url;
        try {
            $r = $this->get($url, fn (string $host) => in_array($host, self::GOOGLE_HOSTS, true) || str_ends_with($host, '.google.com'));
            $final = (string) ($r->effectiveUri() ?? $url);
        } catch (Throwable) {
        }
        if (preg_match('#/maps/place/([^/@?]+)#', $final, $m) === 1) {
            return urldecode(str_replace('+', ' ', $m[1]));
        }
        throw ValidationException::withMessages(['link' => 'That Google link doesn’t show a business. Type your business name and area instead.']);
    }

    /**
     * A Facebook page, Instagram profile or website: its public preview and text, read by WURK AI.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fromPage(string $url): array
    {
        try {
            $r = $this->get($url, fn (string $host) => true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages(['link' => 'That page didn’t open. Check the link, or answer the questions instead.']);
        }
        $html = substr($r->body(), 0, self::MAX_BYTES);
        $meta = fn (string $prop) => preg_match('/<meta[^>]+(?:property|name)=["\']'.preg_quote($prop, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#si', ' ', $html))));
        $page = 'Title: '.($meta('og:title') ?: (preg_match('#<title>(.*?)</title>#si', $html, $t) === 1 ? html_entity_decode(trim($t[1])) : ''))
            ."\nDescription: ".($meta('og:description') ?: $meta('description'))."\nText: ".mb_substr($text, 0, 6000);
        $read = $this->talk->readPage($page);

        return array_filter([
            'business' => array_filter(['name' => $read['name'], 'what' => $read['what'], 'category' => $read['category']]) === [] ? null : ['name' => $read['name'], 'what' => $read['what'], 'category' => $read['category']],
            'area' => $read['area'] === '' && $read['address'] === '' ? null : ['area' => $read['area'], 'address' => $read['address']],
            'offer' => $read['services'] === [] && $read['products'] === [] ? null : ['services' => $read['services'], 'products' => $read['products']],
            'hours' => array_filter($read['hours'], fn ($h) => $h['opens'] !== '') === [] ? null : ['hours' => $read['hours']],
            'contact' => $read['phone'] === '' && $read['whatsapp'] === '' && $read['email'] === '' ? null : ['phone' => $read['phone'], 'whatsapp' => $read['whatsapp'], 'email' => $read['email']],
        ]);
    }

    /**
     * GET a public https page: every address it leads to (redirects too) must be public, and allowed by $host.
     *
     * @param  callable(string): bool  $host
     */
    private function get(string $url, callable $host): Response
    {
        $check = function (string $u) use ($host): void {
            $parts = parse_url($u);
            $name = strtolower((string) ($parts['host'] ?? ''));
            if (($parts['scheme'] ?? '') !== 'https' || $name === '' || isset($parts['user']) || ! $host($name) || ! self::publicHost($name)) {
                throw ValidationException::withMessages(['link' => 'Paste a public https link to your page.']);
            }
        };
        $check($url);

        return Http::timeout(10)->withHeaders(['User-Agent' => 'WURK setup (+https://wurk.co.za)', 'Accept' => 'text/html'])
            ->withOptions(['allow_redirects' => ['max' => 4, 'protocols' => ['https'], 'track_redirects' => true,
                'on_redirect' => function (RequestInterface $request, $response, UriInterface $uri) use ($check): void {
                    $check((string) $uri);
                }], 'stream' => false])
            ->get((string) new Uri($url));
    }

    /** Whether every address the name resolves to is public (no private, loopback or reserved ranges). */
    public static function publicHost(string $name): bool
    {
        if (filter_var($name, FILTER_VALIDATE_IP)) {
            $ips = [$name];
        } elseif (self::$resolver !== null) {
            $ips = (self::$resolver)($name);
        } else {
            $ips = array_merge(array_column(@dns_get_record($name, DNS_A) ?: [], 'ip'), array_column(@dns_get_record($name, DNS_AAAA) ?: [], 'ipv6'));
        }

        return $ips !== [] && array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) === [];
    }
}
