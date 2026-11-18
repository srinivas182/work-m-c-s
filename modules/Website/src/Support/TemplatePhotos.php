<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\Storage;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\TemplateAsset;

/**
 * Demo photos (Sprint 20.3). Every design has photo slots — hero, gallery, team… — named by the stand-in files its pages
 * use (/images/templates/{design}/{slot}-1600.webp). HQ fills a slot with a licensed photo (or, for decorative slots
 * only, a WURK AI banner); the newest photo for a slot replaces the stand-in wherever the design is shown or applied.
 * On a business's site, any photo still coming from a design is a demo photo the owner should replace.
 */
final class TemplatePhotos
{
    /** A stand-in or an HQ demo photo: anything served from a design's own folder, never a business's upload. */
    public const DEMO = '#/(?:images/)?templates/[a-z0-9-]+/[a-z0-9-]+-\d+\.webp#i';

    private const STAND_IN = '#/images/templates/([a-z0-9-]+)/([a-z0-9-]+?)-(\d+)\.webp#';

    /** @var array<string, array<string, array{shows: string, search: string, decorative?: bool}>>|null */
    private static ?array $catalogue = null;

    /** Whether a photo address is a demo photo (from a design, not the business). */
    public static function isDemo(string $src): bool
    {
        return preg_match(self::DEMO, $src) === 1;
    }

    /**
     * The distinct demo photos in some content (a page's JSON or blocks).
     *
     * @return list<string>
     */
    public static function demosIn(mixed $content): array
    {
        $values = is_string($content) ? json_decode($content, true) : $content;
        $found = [];
        if (is_array($values)) {
            array_walk_recursive($values, function ($v) use (&$found): void {
                if (is_string($v) && preg_match(self::DEMO, $v, $m) === 1) {
                    $found[preg_replace('#-\d+\.webp$#', '', $m[0])] = true; // one photo, whatever size
                }
            });
        }

        return array_keys($found);
    }

    /**
     * The slots of a design: the stand-ins its pages use, with what each should show (photos/slots.json).
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{slot: string, shows: string, search: string, decorative: bool}>
     */
    public static function slots(string $templateKey, array $definition): array
    {
        $used = [];
        array_walk_recursive($definition, function ($v) use (&$used, $templateKey): void {
            if (is_string($v) && preg_match(self::STAND_IN, $v, $m) === 1 && $m[1] === $templateKey) {
                $used[$m[2]] = true;
            }
        });
        $known = self::catalogue()[$templateKey] ?? [];
        $slots = array_keys($used + $known);
        sort($slots);

        return array_map(fn (string $slot) => [
            'slot' => $slot,
            'shows' => (string) ($known[$slot]['shows'] ?? 'A photo for “'.$slot.'”'),
            'search' => (string) ($known[$slot]['search'] ?? str_replace('-', ' ', $slot)),
            'decorative' => (bool) ($known[$slot]['decorative'] ?? false),
        ], $slots);
    }

    /**
     * The photo HQ chose for each slot of a design (the newest per slot).
     *
     * @return array<string, TemplateAsset>
     */
    public function chosen(SiteTemplate $template): array
    {
        $chosen = [];
        foreach (TemplateAsset::query()->where('template_id', $template->id)->whereNotNull('slot')->orderBy('created_at')->orderBy('id')->get() as $asset) {
            $chosen[(string) $asset->slot] = $asset; // later rows win
        }

        return $chosen;
    }

    /**
     * A design's definition with each filled slot's stand-in replaced by HQ's photo (same size where there is one).
     *
     * @template T of array
     *
     * @param  T  $definition
     * @return T
     */
    public function resolve(SiteTemplate $template, array $definition): array
    {
        $chosen = $this->chosen($template);
        if ($chosen === []) {
            return $definition;
        }
        $disk = Storage::disk((string) config('wurk.site_assets_disk'));
        $swap = function (mixed &$value) use (&$swap, $chosen, $disk, $template): void {
            if (is_array($value)) {
                foreach ($value as &$item) {
                    $swap($item);
                }
                unset($item);

                return;
            }
            if (! is_string($value) || ! str_contains($value, '/images/templates/')) {
                return;
            }
            $value = (string) preg_replace_callback(self::STAND_IN, function (array $m) use ($chosen, $disk, $template): string {
                $asset = $m[1] === $template->key ? ($chosen[$m[2]] ?? null) : null;
                if ($asset === null) {
                    return $m[0];
                }
                $variants = $asset->variants;
                $widths = array_map('intval', array_keys($variants));
                sort($widths);
                $width = in_array((int) $m[3], $widths, true) ? (int) $m[3] : (int) end($widths);

                return $disk->url($variants[$width]);
            }, $value);
        };
        $swap($definition);

        return $definition;
    }

    /**
     * A design's demo photos, one per place — HQ's photo or the stand-in — for an owner choosing another demo photo.
     *
     * @return list<array{src: string, alt: string, name: string, demo: true}>
     */
    public function demoSet(SiteTemplate $template): array
    {
        $chosen = $this->chosen($template);
        $disk = Storage::disk((string) config('wurk.site_assets_disk'));

        return array_map(fn (array $slot) => [
            'src' => isset($chosen[$slot['slot']])
                ? $disk->url((string) collect($chosen[$slot['slot']]->variants)->sortKeys()->last())
                : '/images/templates/'.$template->key.'/'.$slot['slot'].'-1600.webp',
            'alt' => '', 'name' => $slot['shows'], 'demo' => true,
        ], self::slots($template->key, (array) json_decode((string) $template->draft, true)));
    }

    /** @return array<string, array<string, array{shows: string, search: string, decorative?: bool}>> */
    private static function catalogue(): array
    {
        return self::$catalogue ??= (array) json_decode((string) file_get_contents(base_path('modules/Website/templates/photos/slots.json')), true);
    }
}
