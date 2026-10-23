<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;

/**
 * Header and footer menus (Sprint 4.4). Items point at pages by id, so renaming a
 * page's address never breaks a menu, and a deleted page simply drops out. One
 * header menu serves desktop and phones (no second menu to keep in step).
 */
final class Menus
{
    public const LIMITS = ['header' => 8, 'footer' => 12, 'children' => 8];

    /** Web, email, phone and on-page links only — never javascript: or data: URLs. Shared with templates. */
    public const SAFE_LINK = '#^(https://[^\s]+|mailto:[^\s]+|tel:\+?[0-9 ]{6,20}|\#[A-Za-z][\w-]*)$#';

    /**
     * @param  array<string, mixed>  $input
     * @return array{header: list<array<string, mixed>>, footer: list<array<string, mixed>>}
     */
    public function validate(array $input): array
    {
        /** @var list<string> $pageIds */
        $pageIds = array_values(array_map('strval', Page::query()->pluck('id')->all()));

        return [
            'header' => $this->items($input['header'] ?? [], 'header', $pageIds, nested: true),
            'footer' => $this->items($input['footer'] ?? [], 'footer', $pageIds, nested: false),
        ];
    }

    /**
     * Menus with live addresses, for rendering (editor, preview, public site).
     *
     * @return array{header: list<array{label: string, href: string, children?: list<array{label: string, href: string}>}>, footer: list<array{label: string, href: string}>}
     */
    public function resolve(Site $site): array
    {
        $pages = Page::query()->get(['id', 'slug', 'is_home'])->keyBy('id');
        $href = fn (array $item): ?string => match ($item['type'] ?? '') {
            'page' => ($p = $pages->get($item['page_id'] ?? '')) ? ($p->is_home ? '/' : '/'.$p->slug) : null,
            'link' => (string) ($item['href'] ?? ''),
            default => null,
        };
        $resolve = function (array $items, bool $nested) use (&$resolve, $href): array {
            $out = [];
            foreach ($items as $item) {
                $url = $href($item);
                if ($url === null) {
                    continue; // its page was deleted
                }
                $entry = ['label' => (string) $item['label'], 'href' => $url];
                if ($nested && ! empty($item['children'])) {
                    $entry['children'] = $resolve($item['children'], false);
                }
                $out[] = $entry;
            }

            return $out;
        };

        return ['header' => $resolve($site->menus['header'] ?? [], true), 'footer' => $resolve($site->menus['footer'] ?? [], false)];
    }

    /**
     * @param  list<string>  $pageIds
     * @return list<array<string, mixed>>
     */
    private function items(mixed $items, string $where, array $pageIds, bool $nested, int $depth = 0): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages([$where => $m]);
        $max = $depth > 0 ? self::LIMITS['children'] : self::LIMITS[$where];
        if (! is_array($items) || ! array_is_list($items)) {
            $fail('That menu is not valid.');
        }
        if (count($items) > $max) {
            $fail("Menus can have up to {$max} items — keep them short so visitors find things quickly.");
        }

        $clean = [];
        foreach ($items as $item) {
            $label = is_array($item) ? trim((string) ($item['label'] ?? '')) : '';
            if ($label === '' || mb_strlen($label) > 40) {
                $fail('Every menu item needs a label of up to 40 characters.');
            }
            $type = $item['type'] ?? '';
            $entry = ['label' => $label, 'type' => $type];
            if ($type === 'page') {
                if (! in_array($item['page_id'] ?? null, $pageIds, true)) {
                    $fail("“{$label}” points to a page that doesn't exist.");
                }
                $entry['page_id'] = $item['page_id'];
            } elseif ($type === 'link') {
                $href = trim((string) ($item['href'] ?? ''));
                if (preg_match(self::SAFE_LINK, $href) !== 1) {
                    $fail("“{$label}” needs a link starting with https://, mailto:, tel: or #.");
                }
                $entry['href'] = $href;
            } else {
                $fail("“{$label}” must link to a page or a web address.");
            }
            $children = $item['children'] ?? [];
            if ($children !== [] && ! ($nested && $depth === 0)) {
                $fail('Only the header menu can have one level of sub-items.');
            }
            if ($children !== []) {
                $entry['children'] = $this->items($children, $where, $pageIds, false, $depth + 1);
            }
            $clean[] = $entry;
        }

        return $clean;
    }
}
