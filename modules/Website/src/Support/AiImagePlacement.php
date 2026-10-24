<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Website\Models\SiteAsset;

/**
 * AI images are decorative: allowed for atmosphere (Hero, Image & text), refused in
 * sections that show real work, places or people — enforced on every save.
 */
final class AiImagePlacement
{
    public const ALLOWED = ['Hero', 'ImageText'];

    private const LABELS = [
        'BeforeAfter' => 'Before & after', 'Team' => 'Team', 'Portfolio' => 'Portfolio', 'Gallery' => 'a gallery of your work',
        'RoomsRates' => 'Rooms & rates', 'Testimonials' => 'Reviews', 'Accreditations' => 'Accreditations', 'Header' => 'your logo',
    ];

    /** @param  array{schema: int, blocks: list<array<string, mixed>>}  $document */
    public static function check(array $document): void
    {
        foreach ($document['blocks'] as $block) {
            if (in_array($block['type'], self::ALLOWED, true)) {
                continue;
            }
            $ids = [];
            array_walk_recursive($block['props'], function ($v) use (&$ids): void {
                if (is_string($v) && preg_match('#/sites/[0-9A-Za-z]+/([0-9a-z]{26})-\d+\.webp#', $v, $m) === 1) {
                    $ids[] = $m[1];
                }
            });
            if ($ids === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if (SiteAsset::query()->where('ai_generated', true)->whereRaw("lower(id) in ({$placeholders})", $ids)->exists()) {
                $where = self::LABELS[$block['type']] ?? 'this section';
                throw ValidationException::withMessages(['content' => "AI images can’t be used in {$where}. Use a real photo — AI images are for decoration, like your opening banner."]);
            }
        }
    }
}
