<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\ProductCatalog;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageRender;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\Site;

/**
 * Turns a published page version into finished HTML, once, at publish: the same
 * SiteRenderer the editor uses, run by Node (bootstrap/site-render). The stored file
 * is what visitors get — a renderer failure delays publishing, never a live site.
 */
final readonly class PageRenderer
{
    public function __construct(private TenantContext $context, private Menus $menus) {}

    public function render(PageVersion $version): string
    {
        $page = Page::query()->withTrashed()->findOrFail($version->page_id);
        $out = $this->renderDocument((array) json_decode($version->content, true));
        $body = $out['html'];
        $fonts = $out['fonts'];

        // Only the content is stored; the page shell (stylesheet, scripts, font preloads) is added when
        // the page is served, from the current build — so a deploy never leaves pages pointing at old assets.
        $stored = [
            'body' => $body,
            'fonts' => $fonts,
            'title' => $page->seo_title ?: ($page->is_home ? $this->context->current()->displayName() : $page->title.' · '.$this->context->current()->displayName()),
            'description' => $page->seo_description,
            'image' => SiteFacts::firstImage((array) ($out['blocks'] ?? [])),
            'blocks' => (array) ($out['blocks'] ?? []), // final values per block, for search engines and AI answers
        ];
        $path = 'rendered/'.$this->context->id().'/'.$version->id.'.json';
        Storage::disk((string) config('wurk.site_pages_disk', 'local'))->put($path, (string) json_encode($stored));
        PageRender::query()->updateOrCreate(['page_version_id' => $version->id], ['path' => $path, 'rendered_at' => now(), 'error' => null]);

        return $path;
    }

    /**
     * Render a content document with the site's branding, menus and products (pages and product pages).
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $site  extra site context (e.g. the product a product page shows)
     * @return array{html: string, fonts: list<string>, blocks: array<int|string, mixed>}
     */
    public function renderDocument(array $document, array $site = []): array
    {
        $record = Site::query()->findOrFail($this->context->id());
        $input = [
            'document' => $document,
            'branding' => $record->branding,
            'site' => [
                'businessName' => $this->context->current()->displayName(),
                'logo' => $record->branding['logo'] ?? null,
                'menus' => $this->menus->resolve($record),
                'products' => app(ProductCatalog::class)->published(),
                'formAction' => '/forms/quote', // posts to the business's own address (allowed by its content policy)
                ...$site,
            ],
        ];
        $result = Process::timeout((int) config('wurk.site_render.timeout', 30))
            ->input((string) json_encode($input))
            ->run([(string) config('wurk.site_render.node', 'node'), (string) config('wurk.site_render.script')]);
        if (! $result->successful()) {
            throw new RuntimeException('Rendering failed: '.mb_substr(trim($result->errorOutput()), 0, 200));
        }
        $out = (array) json_decode($result->output(), true);

        return ['html' => (string) ($out['html'] ?? ''), 'fonts' => array_values(array_filter((array) ($out['fonts'] ?? []), 'is_string')), 'blocks' => (array) ($out['blocks'] ?? [])];
    }
}
