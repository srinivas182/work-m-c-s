<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\Menus;
use Wurk\Website\Support\Pages;

/**
 * The whole site, rendered with the real blocks, inside the back office (Sprint 4.4)
 * until Sprint 5 serves public sites. "published" shows exactly the live version.
 */
final class PreviewController
{
    public function __invoke(Request $request, Pages $pages, Menus $menus, TenantContext $context): Response
    {
        $site = $pages->ensureSite();
        $mode = $request->query('mode') === 'published' ? 'published' : 'draft';
        $slug = (string) $request->query('page', '');
        $page = $slug === ''
            ? Page::query()->where('is_home', true)->first()
            : Page::query()->where('slug', $slug)->first();
        abort_if($page === null, 404);

        $content = $mode === 'draft' ? $page->draft : (string) PageVersion::query()->whereKey((string) $page->published_version_id)->value('content');
        $base = route('tenant.website.preview', [], false);
        $link = fn (string $href) => str_starts_with($href, '/')
            ? $base.'?'.http_build_query(array_filter(['page' => ltrim($href, '/'), 'mode' => $mode === 'published' ? 'published' : null]))
            : $href;
        $resolved = $menus->resolve($site);
        $relink = function (array $items) use (&$relink, $link): array {
            return array_map(fn (array $i) => [...$i, 'href' => $link($i['href']), ...(isset($i['children']) ? ['children' => $relink($i['children'])] : [])], $items);
        };

        return Inertia::render('Website::Preview', [
            'mode' => $mode,
            'page' => ['title' => $page->title, 'slug' => $page->slug, 'is_home' => $page->is_home, 'published' => $page->published_version_id !== null],
            'content' => $content === '' ? null : json_decode($content, true),
            'branding' => $site->branding,
            'businessName' => $context->current()->displayName(),
            'menus' => ['header' => $relink($resolved['header']), 'footer' => $relink($resolved['footer'])],
            'pages' => Page::query()->orderBy('position')->get(['title', 'slug', 'is_home'])->toArray(),
            'urls' => ['preview' => $base, 'pages' => route('tenant.website.pages', [], false), 'editor' => route('tenant.website.pages', [], false).'/'.$page->id.'/edit'],
        ]);
    }
}
