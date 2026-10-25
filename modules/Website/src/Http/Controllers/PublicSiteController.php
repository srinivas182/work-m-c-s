<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageRender;
use Wurk\Website\Models\PageSlug;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\FontPreloads;
use Wurk\Website\Support\PublicAssets;

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

            $stored = (array) json_decode((string) Storage::disk((string) config('wurk.site_pages_disk', 'local'))->get($render->path), true);
            $body = (string) ($stored['body'] ?? '');
            $assets = app(PublicAssets::class);
            $html = view('website::public.page', [
                'title' => (string) ($stored['title'] ?? $tenant->displayName()),
                'description' => $stored['description'] ?? null,
                'preloads' => app(FontPreloads::class)->for(array_values(array_filter((array) ($stored['fonts'] ?? []), 'is_string'))),
                'stylesheet' => $assets->stylesheet(),
                'islands' => str_contains($body, 'data-island=') ? $assets->islands() : null,
                'body' => $body,
            ])->render();

            return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', ...$this->securityHeaders()]);
        });
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
