<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;

/** Builds the current business's site from a published template (at signup). */
final readonly class SiteBuilder
{
    public function __construct(private TenantContext $context, private Templates $templates, private AuditLog $audit) {}

    public function apply(string $templateKey): Site
    {
        $published = $this->templates->published($templateKey)
            ?? throw ValidationException::withMessages(['template' => 'That template isn’t available.']);
        if (Site::query()->find($this->context->id()) !== null) {
            throw ValidationException::withMessages(['template' => 'This business already has a website; switch template instead.']);
        }
        $definition = LegalPages::fill($published['definition'], ['business' => $this->context->current()->displayName()]);

        $site = DB::connection($this->context->connectionName())->transaction(function () use ($published, $definition): Site {
            $site = Site::query()->create([
                'tenant_id' => $this->context->id(),
                'category_key' => $published['template']->category?->key,
                'template_key' => $published['template']->key,
                'template_version' => $published['version'],
                'branding' => [...(array) $definition['branding'], 'logo' => null, 'favicon' => null],
                'menus' => ['header' => [], 'footer' => [], 'mobile' => []],
                'settings' => [],
            ]);

            $ids = [];
            foreach ($definition['pages'] as $position => $page) {
                $ids[$page['key']] = Page::query()->create([
                    'title' => $page['title'], 'slug' => $page['slug'], 'position' => $position, 'is_home' => $page['is_home'],
                    'seo_title' => $page['seo_title'], 'seo_description' => $page['seo_description'], 'draft' => json_encode($page['content']),
                ])->id;
            }
            $site->update(['menus' => [...$site->menus, ...self::menusWithPageIds((array) $definition['menus'], $ids)]]);

            return $site;
        });
        $this->audit->record('website.template_applied', 'site', $site->tenant_id, ['template' => $templateKey, 'version' => $published['version']]);

        return $site;
    }

    /**
     * Template menus point at page keys; a business's menus point at page ids.
     *
     * @param  array<string, mixed>  $menus
     * @param  array<string, string>  $ids
     * @return array{header: list<array<string, mixed>>, footer: list<array<string, mixed>>}
     */
    public static function menusWithPageIds(array $menus, array $ids): array
    {
        $map = function (array $items) use (&$map, $ids): array {
            $out = [];
            foreach ($items as $item) {
                $entry = ['label' => $item['label'], 'type' => $item['type']];
                if ($item['type'] === 'page') {
                    if (! isset($ids[$item['page']])) {
                        continue;
                    }
                    $entry['page_id'] = $ids[$item['page']];
                } else {
                    $entry['href'] = $item['href'];
                }
                if (! empty($item['children'])) {
                    $entry['children'] = $map($item['children']);
                }
                $out[] = $entry;
            }

            return $out;
        };

        return ['header' => $map((array) ($menus['header'] ?? [])), 'footer' => $map((array) ($menus['footer'] ?? []))];
    }
}
