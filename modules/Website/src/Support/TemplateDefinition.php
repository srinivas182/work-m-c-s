<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A template: pages (in WURK's page format), menus that point at the template's
 * own pages by key, and default branding.
 *
 *   { "pages": [ { "key": "home", "title": "Home", "slug": "home", "is_home": true,
 *                  "seo_title": null, "seo_description": null, "content": { schema, blocks } } ],
 *     "menus": { "header": [ { "label": "Prices", "type": "page", "page": "prices" } ], "footer": [] },
 *     "branding": { "primary": "#…", "secondary": null, "font_pairing": "…", "corners": "…" } }
 */
final class TemplateDefinition
{
    public const MAX_PAGES = 20;

    /** @return array{pages: list<array<string, mixed>>, menus: array{header: list<array<string, mixed>>, footer: list<array<string, mixed>>}, branding: array<string, mixed>} */
    public static function blank(): array
    {
        return [
            'pages' => [['key' => 'home', 'title' => 'Home', 'slug' => 'home', 'is_home' => true, 'seo_title' => null, 'seo_description' => null, 'content' => ContentDocument::empty()]],
            'menus' => ['header' => [], 'footer' => []],
            'branding' => ['primary' => '#6D4AFF', 'secondary' => null, 'font_pairing' => 'modern', 'corners' => 'soft'],
        ];
    }

    /**
     * @return array{pages: list<array<string, mixed>>, menus: array{header: list<array<string, mixed>>, footer: list<array<string, mixed>>}, branding: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public static function validate(mixed $definition): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['definition' => $m]);
        if (! is_array($definition) || ! is_array($definition['pages'] ?? null) || ! array_is_list($definition['pages'])) {
            $fail('A template needs a list of pages.');
        }
        $count = count($definition['pages']);
        if ($count < 1 || $count > self::MAX_PAGES) {
            $fail('A template has between 1 and '.self::MAX_PAGES.' pages.');
        }

        $pages = [];
        $keys = $slugs = [];
        $homes = 0;
        foreach ($definition['pages'] as $i => $page) {
            $n = $i + 1;
            $key = is_array($page) ? (string) ($page['key'] ?? '') : '';
            $slug = Str::slug((string) ($page['slug'] ?? $key));
            if (preg_match('/^[a-z][a-z0-9-]{0,39}$/', $key) !== 1 || isset($keys[$key])) {
                $fail("Page {$n} needs a unique key (lowercase letters, numbers and dashes).");
            }
            if ($slug === '' || isset($slugs[$slug]) || in_array($slug, (array) config('website.reserved_slugs'), true)) {
                $fail("Page {$n} needs a unique web address that isn't reserved.");
            }
            $title = trim((string) ($page['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 120) {
                $fail("Page {$n} needs a title of up to 120 characters.");
            }
            $isHome = ($page['is_home'] ?? false) === true;
            $homes += $isHome ? 1 : 0;
            $keys[$key] = $slugs[$slug] = true;
            $pages[] = [
                'key' => $key, 'title' => $title, 'slug' => $slug, 'is_home' => $isHome,
                'seo_title' => isset($page['seo_title']) ? mb_substr((string) $page['seo_title'], 0, 70) : null,
                'seo_description' => isset($page['seo_description']) ? mb_substr((string) $page['seo_description'], 0, 170) : null,
                'content' => ContentDocument::validate($page['content'] ?? null),
            ];
        }
        if ($homes !== 1) {
            $fail('A template has exactly one home page.');
        }

        $menus = ['header' => [], 'footer' => []];
        foreach (['header', 'footer'] as $where) {
            $items = $definition['menus'][$where] ?? [];
            if (! is_array($items) || ! array_is_list($items) || count($items) > Menus::LIMITS[$where]) {
                $fail("The {$where} menu is not valid.");
            }
            $menus[$where] = array_map(fn ($item) => self::menuItem($item, $keys, $where === 'header', 0), $items);
        }

        $branding = (array) ($definition['branding'] ?? []);

        return [
            'pages' => $pages,
            'menus' => $menus,
            'branding' => (new Branding)->validateColoursAndType([...self::blank()['branding'], ...$branding]),
        ];
    }

    /**
     * @param  array<string, true>  $pageKeys
     * @return array<string, mixed>
     */
    private static function menuItem(mixed $item, array $pageKeys, bool $nested, int $depth): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['definition' => $m]);
        $label = is_array($item) ? trim((string) ($item['label'] ?? '')) : '';
        if ($label === '' || mb_strlen($label) > 40) {
            $fail('Every menu item needs a label of up to 40 characters.');
        }
        $entry = ['label' => $label, 'type' => $item['type'] ?? ''];
        if ($entry['type'] === 'page') {
            if (! isset($pageKeys[$item['page'] ?? ''])) {
                $fail("“{$label}” points to a page that isn't in this template.");
            }
            $entry['page'] = $item['page'];
        } elseif ($entry['type'] === 'link') {
            if (preg_match(Menus::SAFE_LINK, trim((string) ($item['href'] ?? ''))) !== 1) {
                $fail("“{$label}” needs a link starting with https://, mailto:, tel: or #.");
            }
            $entry['href'] = trim((string) $item['href']);
        } else {
            $fail("“{$label}” must link to a page or a web address.");
        }
        $children = $item['children'] ?? [];
        if ($children !== []) {
            if (! $nested || $depth > 0 || ! is_array($children) || count($children) > Menus::LIMITS['children']) {
                $fail('Only the header menu can have one level of sub-items.');
            }
            $entry['children'] = array_map(fn ($c) => self::menuItem($c, $pageKeys, false, 1), array_values($children));
        }

        return $entry;
    }
}
