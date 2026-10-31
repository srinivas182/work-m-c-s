<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageRender;
use Wurk\Website\Models\PageSlug;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\ProductRender;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ChatBot;
use Wurk\Website\Support\FontPreloads;
use Wurk\Website\Support\ProductPages;
use Wurk\Website\Support\PublicAssets;
use Wurk\Website\Support\SiteFacts;
use Wurk\Website\Support\SiteSeo;

/**
 * Public websites at {handle}.{root}: each page's newest rendered version, wrapped in the page
 * shell from the current build. Suspended → 503; not yet published → coming soon; renamed → 301.
 */
final class PublicSiteController
{
    public function __invoke(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context): Response|RedirectResponse
    {
        $path = trim($request->path(), '/');
        $slug = $path === '' ? null : $path;
        $tenant = $tenants->findByHandle($handle);
        if ($tenant !== null && $tenant->isActive() && $slug === 'cart') {
            $cart = $context->run($tenant, function () use ($tenant): ?Response {
                $render = ProductRender::query()->find(ProductPages::cartId($tenant->tenantId())); // only while the business takes orders online

                return $render === null ? null : $this->respond($tenant, $render->path, $render->rendered_at?->getTimestamp(), '/cart', false);
            });
            if ($cart !== null) {
                return $cart;
            }
            // Not taking orders: an older page at /cart (made before the shop reserved it) still shows.
        }
        if ($tenant !== null && $tenant->isActive() && preg_match('#^shop/([a-z0-9-]+)$#', (string) $slug, $m) === 1) {
            return $context->run($tenant, function () use ($tenant, $m): Response {
                $render = ProductRender::query()->where('slug', $m[1])->first(); // only published products have one
                if ($render === null) {
                    return $this->status($tenant->displayName(), 404, 'Product not found', 'This product isn’t available.', home: true);
                }

                return $this->respond($tenant, $render->path, $render->rendered_at?->getTimestamp(), '/shop/'.$m[1], false);
            });
        }
        if ($tenant === null || ($slug !== null && preg_match('/^[a-z0-9-]+$/', $slug) !== 1)) {
            return $this->status($tenant?->displayName() ?? 'WURK', 404, 'Page not found', 'This page doesn’t exist.', home: $tenant !== null);
        }
        if (! $tenant->isActive()) {
            return $this->status($tenant->displayName(), 503, 'Temporarily unavailable', 'This website is temporarily unavailable. Please check back soon.', home: false, headers: ['Retry-After' => '3600']);
        }

        return $context->run($tenant, function () use ($slug, $tenant): Response|RedirectResponse {
            $page = $slug === null ? Page::query()->where('is_home', true)->first() : Page::query()->where('slug', $slug)->first();
            if ($page === null && $slug !== null && ($old = PageSlug::query()->find($slug)) !== null) {
                $now = Page::query()->find($old->page_id);
                if ($now !== null) {
                    return new RedirectResponse($now->is_home ? '/' : '/'.$now->slug, 301, $this->securityHeaders()); // shared links keep working
                }
            }
            // Only pages that are published now. Prefer the published version's render; while a new
            // publish is still rendering, the previous render stays live.
            $render = $page === null || $page->published_version_id === null ? null
                : (PageRender::query()->whereKey($page->published_version_id)->whereNotNull('path')->first()
                    ?? PageRender::query()->whereIn('page_version_id', PageVersion::query()->where('page_id', $page->id)->select('id'))
                        ->whereNotNull('path')->orderByDesc('rendered_at')->first());
            if ($render === null || $render->path === null) {
                return $slug === null
                    ? $this->status($tenant->displayName(), 200, 'Coming soon', 'This website is being prepared. Please check back soon.', home: false)
                    : $this->status($tenant->displayName(), 404, 'Page not found', 'This page doesn’t exist.', home: true);
            }

            return $this->respond($tenant, $render->path, $render->rendered_at?->getTimestamp(), $page->is_home ? '/' : '/'.$page->slug, $page->is_home);
        });
    }

    /** The page shell around stored content (pages and product pages share it): caching, assets, headers. */
    private function respond(CurrentTenant $tenant, string $path, ?int $renderedAt, string $canonical, bool $home): Response
    {
        // Revalidation: the tag changes with the render and with the build, so a new publish or a
        // deploy is never hidden behind a cached copy. Cache-Tag lets the CDN purge a whole site (Sprint 17).
        $assets = app(PublicAssets::class);
        // The chat: on, and not hidden on this page (decision B). Part of the ETag, so switching it reaches visitors at once.
        $chat = app(ChatBot::class)->settings();
        $chatHere = $chat['enabled'] && ! in_array($canonical, $chat['hidden'], true);
        $etag = '"'.sha1($path.'|'.$renderedAt.'|'.$assets->stylesheet().'|'.($chatHere ? 'chat' : '')).'"';
        $caching = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=0, must-revalidate', 'Cache-Tag' => 'site-'.$tenant->tenantId()];
        if (request()->header('If-None-Match') === $etag) {
            return new Response('', 304, [...$caching, ...$this->securityHeaders()]);
        }

        $site = Site::query()->find($tenant->tenantId());
        $facts = app(SiteFacts::class)->collect(); // once per request: structured data and the WhatsApp button
        $stored = (array) json_decode((string) Storage::disk((string) config('wurk.site_pages_disk', 'local'))->get($path), true);
        $body = (string) ($stored['body'] ?? '');
        $html = view('website::public.page', [
            'title' => (string) ($stored['title'] ?? $tenant->displayName()),
            'description' => $stored['description'] ?? null,
            'preloads' => app(FontPreloads::class)->for(array_values(array_filter((array) ($stored['fonts'] ?? []), 'is_string'))),
            'stylesheet' => $assets->stylesheet(),
            'islands' => str_contains($body, 'data-island=') ? $assets->islands() : null,
            'chat' => $chatHere, // decided here — never by whether an asset address exists
            'chatScript' => $chatHere ? $assets->chat() : null,
            'body' => $body,
            'canonical' => request()->getSchemeAndHttpHost().$canonical,
            'image' => ($img = $stored['image'] ?? null) ? request()->getSchemeAndHttpHost().$img : null,
            'siteName' => $tenant->displayName(),
            // WhatsApp chat button from the published contact details: digits, a leading 0 becomes 27
            // (as the Contact section does). A plain link — no third-party script.
            'whatsapp' => ($wa = preg_replace('/\D/', '', $facts['contact']['whatsapp']) ?? '') === '' ? null : (str_starts_with($wa, '0') ? '27'.substr($wa, 1) : $wa),
            'favicon' => $site?->branding['favicon'] ?? null,
            'jsonLd' => $home ? json_encode(app(SiteSeo::class)->localBusiness($facts, request()->getSchemeAndHttpHost()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : null,
        ])->render();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', ...$caching, ...$this->securityHeaders()]);
    }

    /** @param  array<string, string>  $headers */
    private function status(string $business, int $code, string $heading, string $message, bool $home, array $headers = []): Response
    {
        $html = view('website::public.status', compact('business', 'heading', 'message', 'home'))->render();

        return new Response($html, $code, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex', ...$headers, ...$this->securityHeaders()]);
    }

    /**
     * Nothing from other sites (no third-party requests — POPIA), no framing, no sniffing.
     * Inline style attributes carry each site's theme colours, hence 'unsafe-inline' for styles only.
     *
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
        ];
        if (! Vite::isRunningHot()) { // the development server lives on another origin
            $headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";
        }

        return $headers;
    }
}
