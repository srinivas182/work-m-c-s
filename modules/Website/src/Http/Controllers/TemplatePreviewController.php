<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Website\Support\Templates;

/**
 * Public preview of a published template (signup's "Preview" link). Drafts are never shown. `?primary=#rrggbb` and
 * `?secondary=#rrggbb` show it in other colours (Sprint 18.5b: every template is checked for contrast in many colours).
 */
final class TemplatePreviewController
{
    public function __invoke(Request $request, string $template, Templates $templates): Response
    {
        $published = $templates->published($template) ?? abort(404);
        $definition = $published['definition'];
        $pageKey = (string) $request->query('page', '');
        /** @var list<array{key: string, title: string, is_home: bool, content: array<string, mixed>}> $list */
        $list = (array) $definition['pages'];
        $pages = collect($list);
        $page = ($pageKey === '' ? $pages->firstWhere('is_home', true) : $pages->firstWhere('key', $pageKey)) ?? abort(404);
        $base = route('website.template-preview', ['template' => $template], false);
        $href = fn (array $item) => $item['type'] === 'page' ? $base.'?page='.$item['page'] : $item['href'];
        $menu = fn (array $items) => array_map(fn (array $i) => ['label' => $i['label'], 'href' => $href($i), ...(isset($i['children']) ? ['children' => array_map(fn ($c) => ['label' => $c['label'], 'href' => $href($c)], $i['children'])] : [])], $items);

        return Inertia::render('Website::TemplatePreview', [
            'template' => ['key' => $published['template']->key, 'name' => $published['template']->name, 'category' => $published['template']->category?->name],
            'page' => ['key' => $page['key'], 'title' => $page['title']],
            'pages' => $pages->map(fn ($p) => ['key' => $p['key'], 'title' => $p['title']])->values()->all(),
            'content' => $page['content'],
            'branding' => [...(array) $definition['branding'], ...$this->colours($request), 'logo' => null, 'favicon' => null],
            'menus' => ['header' => $menu($definition['menus']['header'] ?? []), 'footer' => $menu($definition['menus']['footer'] ?? [])],
            'previewBase' => $base,
        ]);
    }

    /** @return array{primary?: string, secondary?: string} */
    private function colours(Request $request): array
    {
        $out = [];
        foreach (['primary', 'secondary'] as $key) {
            $value = $request->query($key);
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $out[$key] = strtoupper($value);
            }
        }

        return $out;
    }
}
