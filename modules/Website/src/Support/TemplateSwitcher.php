<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Models\SiteSnapshot;

/**
 * Switches a business to another design in its category, keeping its content
 * (spec §3, 6.1). Each page gets the new design's sections; the owner's content
 * moves into matching sections (by type, in order); only style settings come
 * from the new design. Nothing is dropped: unmatched sections stay on the page
 * (above the footer) and pages the new design lacks stay as they are. The whole
 * site is snapshotted first. Drafts change; the live site waits for "Publish".
 */
final readonly class TemplateSwitcher
{
    /** Props that describe how a section looks, not what it says — these follow the new design. */
    public const STYLE_PROPS = ['layout', 'variant', 'style', 'columns', 'align', 'tone', 'background', 'sticky', 'autoplay'];

    public function __construct(private TenantContext $context, private Templates $templates, private AuditLog $audit) {}

    public function switch(string $templateKey, ?string $by): Site
    {
        $site = Site::query()->findOrFail($this->context->id());
        $published = $this->templates->published($templateKey);
        if ($published === null || $published['template']->category?->key !== $site->category_key) {
            throw ValidationException::withMessages(['template' => 'Choose one of the designs for your kind of business.']);
        }
        if ($site->template_key === $templateKey) {
            return $site;
        }
        $definition = $published['definition'];

        DB::connection($this->context->connectionName())->transaction(function () use ($site, $definition, $published, $by): void {
            SiteSnapshot::query()->create([
                'reason' => 'template_switch', 'template_key' => $site->template_key, 'created_by' => $by, 'created_at' => now(),
                'snapshot' => json_encode([
                    'site' => $site->only(['branding', 'menus', 'template_key', 'template_version']),
                    'pages' => Page::query()->get(['id', 'title', 'slug', 'position', 'is_home', 'seo_title', 'seo_description', 'draft'])->toArray(),
                ]),
            ]);

            $existing = Page::query()->get()->keyBy('slug');
            $home = Page::query()->where('is_home', true)->first();
            $position = (int) Page::query()->max('position');
            foreach ($definition['pages'] as $templatePage) {
                $page = $templatePage['is_home'] ? $home : $existing->get($templatePage['slug']);
                if ($page === null) {
                    Page::query()->create([
                        'title' => $templatePage['title'], 'slug' => $templatePage['slug'], 'position' => ++$position, 'is_home' => false,
                        'seo_title' => $templatePage['seo_title'], 'seo_description' => $templatePage['seo_description'], 'draft' => json_encode($templatePage['content']),
                    ]);

                    continue;
                }
                $merged = self::carryOver((array) json_decode($page->draft, true), $templatePage['content']);
                $page->update(['draft' => json_encode(ContentDocument::validate($merged)), 'draft_revision' => $page->draft_revision + 1, 'updated_by' => $by]);
            }

            $site->update([
                'template_key' => $published['template']->key,
                'template_version' => $published['version'],
                'branding' => [...$site->branding, 'font_pairing' => $definition['branding']['font_pairing'], 'corners' => $definition['branding']['corners']],
            ]);
        });
        $this->audit->record('website.template_switched', 'site', $site->tenant_id, ['from' => $site->getOriginal('template_key'), 'to' => $templateKey]);

        return $site->fresh() ?? $site;
    }

    /**
     * The new design's sections with the owner's content moved in.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    public static function carryOver(array $old, array $new): array
    {
        $pool = [];
        foreach ((array) ($old['blocks'] ?? []) as $block) {
            $pool[$block['type']][] = $block;
        }

        $blocks = [];
        foreach ((array) ($new['blocks'] ?? []) as $block) {
            $mine = isset($pool[$block['type']]) && $pool[$block['type']] !== [] ? array_shift($pool[$block['type']]) : null;
            if ($mine !== null) {
                $style = array_intersect_key((array) $block['props'], array_flip(self::STYLE_PROPS));
                $block = [...$block, 'id' => $mine['id'], 'version' => max((int) $mine['version'], (int) $block['version']), 'props' => [...(array) $mine['props'], ...$style]];
            }
            $blocks[] = $block;
        }

        // Whatever the new design has no place for stays on the page, above the footer.
        $leftover = array_merge(...array_values($pool) ?: [[]]);
        if ($leftover !== []) {
            $footerAt = array_search('Footer', array_column($blocks, 'type'), true);
            array_splice($blocks, $footerAt === false ? count($blocks) : (int) $footerAt, 0, $leftover);
        }

        return ['schema' => ContentDocument::SCHEMA, 'blocks' => $blocks];
    }
}
