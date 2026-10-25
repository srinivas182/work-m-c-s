<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\Storage;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageRender;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\Site;

/**
 * What search engines and AI assistants are told about a business — only what the owner has
 * published. Opening hours only once the owner has confirmed them (templates ship sample hours);
 * a price of 0 means "ask us", so such services carry no price.
 */
final readonly class SiteFacts
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{name: string, description: string, image: string|null, contact: array{address: string, phone: string, whatsapp: string, email: string},
     *   hours: list<array{opens: string, closes: string}>|null, services: list<array{name: string, description: string, price: int, from: bool}>,
     *   pages: list<array{title: string, path: string, description: string|null, lastmod: string|null}>}
     */
    public function collect(): array
    {
        $site = Site::query()->find($this->context->id());
        $facts = ['name' => $this->context->current()->displayName(), 'description' => '', 'image' => null,
            'contact' => ['address' => '', 'phone' => '', 'whatsapp' => '', 'email' => ''], 'hours' => null, 'services' => [], 'pages' => []];
        $hoursConfirmed = ! empty($site?->settings['checklist']['hours'] ?? false);
        $seen = [];

        foreach (Page::query()->whereNotNull('published_version_id')->orderBy('position')->get() as $page) {
            // What visitors see: the render's final block values (defaults filled in); the stored content
            // only until the first render finishes.
            $render = PageRender::query()->whereKey($page->published_version_id)->whereNotNull('path')->first();
            $stored = $render?->path ? (array) json_decode((string) Storage::disk((string) config('wurk.site_pages_disk', 'local'))->get($render->path), true) : [];
            // (An empty list counts as missing: `??` alone would keep it and lose every fact.)
            $blocks = ! empty($stored['blocks']) ? (array) $stored['blocks']
                : (array) (json_decode((string) PageVersion::query()->whereKey($page->published_version_id)->value('content'), true)['blocks'] ?? []);
            $facts['pages'][] = ['title' => $page->title, 'path' => $page->is_home ? '/' : '/'.$page->slug, 'description' => $page->seo_description,
                'lastmod' => ($at = PageRender::query()->whereKey($page->published_version_id)->value('rendered_at')) === null ? null : (string) $at];
            foreach ($blocks as $b) {
                $p = (array) ($b['props'] ?? []);
                switch ($b['type'] ?? '') {
                    case 'Hero':
                        if ($page->is_home && $facts['description'] === '') {
                            $facts['description'] = trim((string) ($p['text'] ?? ''));
                            $facts['image'] ??= self::firstImage($p);
                        }
                        break;
                    case 'Contact':
                        foreach (array_keys($facts['contact']) as $field) {
                            $value = trim((string) ($p[$field] ?? ''));
                            if ($value !== '' && $facts['contact'][$field] === '') {
                                $facts['contact'][$field] = $value;
                            }
                        }
                        break;
                    case 'OpeningHours':
                        if ($hoursConfirmed && $facts['hours'] === null) {
                            $facts['hours'] = [];
                            foreach ((array) ($p['hours'] ?? []) as $h) {
                                $facts['hours'][] = ['opens' => (string) ($h['opens'] ?? ''), 'closes' => (string) ($h['closes'] ?? '')];
                            }
                        }
                        break;
                    case 'ServicesPrices':
                        foreach ((array) ($p['items'] ?? []) as $i) {
                            $name = trim((string) ($i['name'] ?? ''));
                            if ($name === '') {
                                continue;
                            }
                            $service = ['name' => $name, 'description' => trim((string) ($i['description'] ?? '')), 'price' => (int) ($i['price'] ?? 0), 'from' => (bool) ($i['from'] ?? false)];
                            // Once per service (a home-page summary often repeats the Services page);
                            // a copy with a price wins over one without.
                            $key = mb_strtolower($name);
                            if (! isset($seen[$key])) {
                                $seen[$key] = count($facts['services']);
                                $facts['services'][] = $service;
                            } elseif ($facts['services'][$seen[$key]]['price'] === 0 && $service['price'] > 0) {
                                $facts['services'][$seen[$key]] = $service;
                            }
                        }
                        break;
                }
            }
        }
        if ($facts['description'] === '') {
            $facts['description'] = (string) ($facts['pages'][0]['description'] ?? '');
        }

        return $facts;
    }

    /**
     * The first photo in a block's props (e.g. images[0].src).
     *
     * @param  array<mixed>  $props
     */
    public static function firstImage(array $props): ?string
    {
        $found = null;
        array_walk_recursive($props, function ($v, $k) use (&$found): void {
            if ($found === null && is_string($v) && in_array($k, ['src', 'image', 'before', 'after'], true) && preg_match('#^/(storage|images)/[^ ]+\.(webp|jpe?g|png)$#', $v) === 1) {
                $found = $v;
            }
        });

        return $found;
    }
}
