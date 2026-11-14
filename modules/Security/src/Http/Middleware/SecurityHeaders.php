<?php

declare(strict_types=1);

namespace Wurk\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (Sprint 18.1): no MIME sniffing, no framing, a strict referrer and permissions
 * policy, HSTS over HTTPS, and a Content Security Policy for every HTML page that doesn't set its own (the public
 * websites, record links and payment hand-offs set stricter or different ones). Scripts need a per-request nonce, so
 * nothing injected into a page can run. `WURK_CSP=report-only` reports instead of blocking; `off` sends none.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $csp = self::mode() !== 'off' && ! Vite::isRunningHot(); // the development server lives on another origin
        if ($csp) {
            Vite::useCspNonce();
        }
        $response = $next($request);
        $h = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
        ];
        foreach ($defaults as $name => $value) {
            if (! $h->has($name)) {
                $h->set($name, $value);
            }
        }
        if ($request->isSecure() && (bool) config('wurk.security.hsts', true) && ! $h->has('Strict-Transport-Security')) {
            $ours = str_ends_with($request->getHost(), (string) config('wurk.domains.root'));
            $h->set('Strict-Transport-Security', 'max-age=31536000'.($ours ? '; includeSubDomains' : '')); // a business's own domain: only itself
        }
        $html = str_contains((string) $h->get('Content-Type', 'text/html'), 'text/html');
        if ($csp && $html && ! $h->has('Content-Security-Policy') && ! $h->has('Content-Security-Policy-Report-Only')) {
            $h->set(self::mode() === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', self::policy((string) Vite::cspNonce(), $request->isSecure()));
        }

        return $response;
    }

    /** enforce | report-only | off */
    public static function mode(): string
    {
        $mode = (string) config('wurk.security.csp', 'enforce');

        return in_array($mode, ['enforce', 'report-only', 'off'], true) ? $mode : 'enforce';
    }

    /**
     * The back office's policy: WURK's own scripts (with this request's nonce), its own styles (inline styles allowed —
     * React sets them as attributes), and files from wherever uploads are stored.
     */
    public static function policy(string $nonce, bool $secure): string
    {
        $assets = self::hosts([(string) config('app.asset_url')]);
        $storage = self::hosts(self::storageUrls());
        $src = fn (array $extra) => implode(' ', array_unique(["'self'", ...$extra]));

        return implode('; ', array_filter([
            "default-src 'self'",
            'script-src '.$src([...$assets, "'nonce-{$nonce}'"]),
            'style-src '.$src([...$assets, "'unsafe-inline'"]),
            'img-src '.$src(['data:', 'blob:', ...$assets, ...$storage]),
            'media-src '.$src(['blob:', ...$storage]),
            'font-src '.$src(['data:', ...$assets]),
            'connect-src '.$src($storage),
            "frame-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            $secure ? 'upgrade-insecure-requests' : null,
        ]));
    }

    /** @return list<string> where uploaded files are served or sent from (other than WURK's own address) */
    private static function storageUrls(): array
    {
        $urls = [];
        foreach ((array) config('filesystems.disks', []) as $disk) {
            if (! is_array($disk)) {
                continue;
            }
            foreach (['url', 'endpoint'] as $key) {
                if (is_string($disk[$key] ?? null)) {
                    $urls[] = $disk[$key];
                }
            }
            if (($disk['driver'] ?? null) === 's3' && is_string($disk['bucket'] ?? null) && $disk['bucket'] !== '' && empty($disk['endpoint'])) {
                $urls[] = 'https://'.$disk['bucket'].'.s3.'.($disk['region'] ?? 'af-south-1').'.amazonaws.com'; // direct uploads to S3
            }
        }

        return $urls;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string> "https://host[:port]" for each absolute URL that isn't WURK's own address
     */
    private static function hosts(array $urls): array
    {
        $app = parse_url((string) config('app.url'), PHP_URL_HOST);
        $out = [];
        foreach ($urls as $url) {
            $p = parse_url($url);
            if (! is_array($p) || ! isset($p['host'], $p['scheme']) || $p['host'] === $app || ! in_array($p['scheme'], ['https', 'http'], true)) {
                continue;
            }
            $out[] = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        }

        return array_values(array_unique($out));
    }
}
