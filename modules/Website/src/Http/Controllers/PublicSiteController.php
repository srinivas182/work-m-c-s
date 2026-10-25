<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageRender;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\FontPreloads;
use Wurk\Website\Support\PublicAssets;

/**
 * Public websites at {handle}.{root}: the stored HTML of each page's newest rendered version.
 * (Statuses, redirects and caching are completed in 5.2.)
 */
final class PublicSiteController
{
    public function __invoke(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context): Response
    {
        $path = trim($request->path(), '/');
        $slug = $path === '' ? null : $path;
        if ($slug !== null && preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            abort(404);
        }
        $tenant = $tenants->findByHandle($handle) ?? abort(404);

        return $context->run($tenant, function () use ($slug): Response {
            $page = $slug === null ? Page::query()->where('is_home', true)->first() : Page::query()->where('slug', $slug)->first();
            if ($page === null) {
                abort(404);
            }
            // The newest version that has finished rendering: while a new publish renders, the previous one stays live.
            $render = PageRender::query()->whereIn('page_version_id', PageVersion::query()->where('page_id', $page->id)->select('id'))
                ->whereNotNull('path')->orderByDesc('rendered_at')->first();
            if ($render === null || $render->path === null) {
                abort(404);
            }
            $stored = (array) json_decode((string) (Storage::disk((string) config('wurk.site_pages_disk', 'local'))->get($render->path) ?? abort(404)), true);
            $body = (string) ($stored['body'] ?? '');
            $assets = app(PublicAssets::class);
            $html = view('website::public.page', [
                'title' => (string) ($stored['title'] ?? ''),
                'description' => $stored['description'] ?? null,
                'preloads' => app(FontPreloads::class)->for(array_values(array_filter((array) ($stored['fonts'] ?? []), 'is_string'))),
                'stylesheet' => $assets->stylesheet(),
                'islands' => str_contains($body, 'data-island=') ? $assets->islands() : null,
                'body' => $body,
            ])->render();

            return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
    }
}
