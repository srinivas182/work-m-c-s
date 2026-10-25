<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Wurk\Tenancy\Contracts\TenantContext;
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
        $site = Site::query()->findOrFail($this->context->id());
        $input = [
            'document' => json_decode($version->content, true),
            'branding' => $site->branding,
            'site' => [
                'businessName' => $this->context->current()->displayName(),
                'logo' => $site->branding['logo'] ?? null,
                'menus' => $this->menus->resolve($site),
                'formAction' => route('website.forms.quote', ['handle' => $this->context->current()->handle()], false),
            ],
        ];

        $result = Process::timeout((int) config('wurk.site_render.timeout', 30))
            ->input((string) json_encode($input))
            ->run([(string) config('wurk.site_render.node', 'node'), (string) config('wurk.site_render.script')]);
        if (! $result->successful()) {
            throw new RuntimeException('Rendering failed: '.mb_substr(trim($result->errorOutput()), 0, 200));
        }
        $out = (array) json_decode($result->output(), true);
        $body = (string) ($out['html'] ?? '');
        $fonts = array_values(array_filter((array) ($out['fonts'] ?? []), 'is_string'));

        // Only the content is stored; the page shell (stylesheet, scripts, font preloads) is added when
        // the page is served, from the current build — so a deploy never leaves pages pointing at old assets.
        $stored = [
            'body' => $body,
            'fonts' => $fonts,
            'title' => $page->seo_title ?: ($page->is_home ? $this->context->current()->displayName() : $page->title.' · '.$this->context->current()->displayName()),
            'description' => $page->seo_description,
        ];
        $path = 'rendered/'.$this->context->id().'/'.$version->id.'.json';
        Storage::disk((string) config('wurk.site_pages_disk', 'local'))->put($path, (string) json_encode($stored));
        PageRender::query()->updateOrCreate(['page_version_id' => $version->id], ['path' => $path, 'rendered_at' => now(), 'error' => null]);

        return $path;
    }
}
