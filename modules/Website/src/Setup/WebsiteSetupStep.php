<?php

declare(strict_types=1);

namespace Wurk\Website\Setup;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Ai\Contracts\AiActionRefused;
use Wurk\Platform\Contracts\SetupStep;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Ai\SiteGenerator;
use Wurk\Website\Contracts\SiteTemplates;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ContentDocument;
use Wurk\Website\Support\OnlineImport;
use Wurk\Website\Support\SiteAssets;

/**
 * Setting up by talking (23.1), the website: a design for the business's kind of work (when it has no site yet), its
 * contact details and opening hours exactly as the owner gave them, the services and prices they named, and the text
 * written by the AI site writer (which never invents prices, hours or testimonials). Pages stay drafts until published.
 */
final readonly class WebsiteSetupStep implements SetupStep
{
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(private TenantContext $context, private SiteTemplates $templates, private SiteGenerator $writer, private OnlineImport $online, private SiteAssets $assets) {}

    public function order(): int
    {
        return 10;
    }

    public function preview(array $brief): array
    {
        $lines = [];
        if (Site::query()->find($this->context->id()) === null) {
            [$category, $design] = $this->design($brief);
            $lines[] = "Make your website from the “{$design['name']}” design ({$category['name']})";
        }
        $contact = array_filter([$brief['phone'] ?? '', $brief['whatsapp'] !== '' ? 'WhatsApp '.$brief['whatsapp'] : '', $brief['email'] ?? '', $brief['address'] ?? '']);
        if ($contact !== []) {
            $lines[] = 'Contact details: '.implode(', ', $contact);
        }
        if (($hours = self::hoursText((array) $brief['hours'])) !== '') {
            $lines[] = 'Opening hours: '.$hours;
        }
        if ($brief['services'] !== []) {
            $lines[] = 'Services and prices on your website: '.implode(', ', array_map(fn ($s) => $s['name'].($s['price_cents'] > 0 ? ' R'.number_format($s['price_cents'] / 100, 2) : ''), $brief['services']));
        }
        $photos = count(self::chosen($brief));
        if ($photos > 0) {
            $lines[] = 'Use '.$photos.' of your own '.($photos === 1 ? 'photo' : 'photos').' in the opening banner and gallery';
        }
        if (($brief['reviews_url'] ?? '') !== '') {
            $lines[] = 'Link to your reviews on Google (they stay on Google; nothing is copied)';
        }
        $lines[] = 'Write the text on every page with WURK AI (your first website is free)';

        return $lines;
    }

    public function apply(array $brief, string $by): array
    {
        $done = [];
        if (Site::query()->find($this->context->id()) === null) {
            [, $design] = $this->design($brief);
            $this->templates->apply($design['key']);
            $done[] = "Website made from the “{$design['name']}” design";
        }
        $hours = array_values((array) $brief['hours']);
        $open = array_filter($hours, fn ($h) => ($h['opens'] ?? '') !== '');
        $photos = [];
        foreach (self::chosen($brief) as $n => $url) { // kept through the same checks as an upload (re-encoded, quota)
            try {
                ['body' => $body] = $this->online->photo($url);
                $path = (string) tempnam(sys_get_temp_dir(), 'wurk-setup-photo-');
                try {
                    file_put_contents($path, $body);
                    $asset = $this->assets->store(new UploadedFile($path, 'photo-'.($n + 1).'.jpg', null, null, true), $by, false, 'From '.mb_substr((string) $brief['photos'][$n]['source'], 0, 100));
                } finally {
                    @unlink($path);
                }
                $photos[] = $this->assets->photo($asset, (string) ($brief['name'] ?? ''));
            } catch (Throwable) {
                continue;
            }
        }
        if (count($photos) < count(self::chosen($brief))) {
            $done[] = (count(self::chosen($brief)) - count($photos)).' photo(s) couldn’t be kept: add them in Website → Media';
        }
        $reviews = (string) ($brief['reviews_url'] ?? '');
        DB::connection($this->context->connectionName())->transaction(function () use ($brief, $hours, $open, $by, $photos, $reviews): void {
            $gallery = false;
            foreach (Page::query()->orderBy('position')->get() as $page) {
                $doc = (array) json_decode($page->draft, true);
                $changed = false;
                foreach ($doc['blocks'] as &$block) {
                    if ($block['type'] === 'Contact') {
                        foreach (['address', 'phone', 'whatsapp', 'email'] as $f) {
                            if (($brief[$f] ?? '') !== '') {
                                $block['props'][$f] = $brief[$f];
                                $changed = true;
                            }
                        }
                    }
                    if ($photos !== [] && $block['type'] === 'Hero' && $page->is_home) {
                        $block['props']['images'] = $photos;
                        $changed = true;
                    }
                    if ($photos !== [] && $block['type'] === 'Gallery' && ! $gallery) {
                        $block['props']['images'] = array_map(fn ($p) => ['src' => $p['src'], 'alt' => $p['alt'], 'asset' => $p['asset'], 'srcset' => $p['srcset'], 'width' => $p['width'], 'height' => $p['height']], $photos);
                        $gallery = $changed = true;
                    }
                    if ($reviews !== '' && $block['type'] === 'Testimonials') {
                        $block['props']['moreLabel'] = 'Read our reviews on Google';
                        $block['props']['moreHref'] = $reviews;
                        $changed = true;
                    }
                    if ($block['type'] === 'OpeningHours' && $open !== []) {
                        $block['props']['hours'] = array_map(fn ($h) => ['opens' => (string) $h['opens'], 'closes' => (string) $h['closes']], $hours);
                        $changed = true;
                    }
                }
                unset($block);
                if ($changed) {
                    $page->update(['draft' => json_encode(ContentDocument::validate($doc)), 'draft_revision' => $page->draft_revision + 1, 'updated_by' => $by]);
                }
            }
            if ($open !== []) { // the owner gave these hours: they count as confirmed (launch checklist)
                $site = Site::query()->findOrFail($this->context->id());
                $site->update(['settings' => [...$site->settings, 'checklist' => [...(array) ($site->settings['checklist'] ?? []), 'hours' => true]]]);
            }
        });
        $done[] = 'Contact details and opening hours filled in';
        if ($photos !== []) {
            $done[] = count($photos).' of your photos added';
        }
        if ($reviews !== '') {
            $done[] = 'Linked to your Google reviews';
        }
        try {
            $services = array_values(array_map(fn (array $s): array => ['name' => (string) $s['name'], 'price' => (int) $s['price_cents']], (array) $brief['services']));
            $pages = $this->writer->generate(['business' => (string) ($brief['name'] !== '' ? $brief['name'] : $this->context->current()->displayName()),
                'what' => (string) $brief['what'], 'area' => (string) $brief['area'], 'tone' => 'warm and professional', 'services' => $services],
                'setup-'.substr(md5((string) json_encode($brief)), 0, 12), $by);
            $done[] = "Text written on {$pages} pages";
        } catch (AiActionRefused $e) {
            $done[] = 'Text not written: '.$e->getMessage();
        }

        return $done;
    }

    /**
     * The category the owner's work fits (the first one when it couldn't be told) and its first design.
     *
     * @param  array<string, mixed>  $brief
     * @return array{0: array{key: string, name: string}, 1: array{key: string, name: string}}
     */
    private function design(array $brief): array
    {
        $catalogue = array_values(array_filter($this->templates->catalogue(), fn ($c) => $c['templates'] !== []));
        $category = collect($catalogue)->firstWhere('key', $brief['category']) ?? $catalogue[0]
            ?? throw new \RuntimeException('No website designs are installed.');

        return [['key' => $category['key'], 'name' => $category['name']], ['key' => $category['templates'][0]['key'], 'name' => $category['templates'][0]['name']]];
    }

    /**
     * The photos the owner chose to use (23.2), by their place in the brief.
     *
     * @param  array<string, mixed>  $brief
     * @return array<int, string>
     */
    private static function chosen(array $brief): array
    {
        $urls = [];
        foreach (array_values((array) ($brief['photos'] ?? [])) as $n => $p) {
            if (($p['chosen'] ?? false) === true && is_string($p['url'] ?? null)) {
                $urls[$n] = $p['url'];
            }
        }

        return $urls;
    }

    /**
     * "Mon–Fri 08:00–17:00, Sat 09:00–13:00" from seven rows.
     *
     * @param  array<int, array{opens?: string, closes?: string}>  $hours
     */
    public static function hoursText(array $hours): string
    {
        $parts = [];
        $rows = array_values($hours);
        for ($d = 0; $d < 7; $d++) {
            $h = $rows[$d] ?? ['opens' => '', 'closes' => ''];
            if (($h['opens'] ?? '') === '') {
                continue;
            }
            $end = $d;
            while ($end + 1 < 7 && ($rows[$end + 1] ?? []) === $h) {
                $end++;
            }
            $parts[] = self::DAYS[$d].($end > $d ? '–'.self::DAYS[$end] : '').' '.$h['opens'].'–'.($h['closes'] ?? '');
            $d = $end;
        }

        return implode(', ', $parts);
    }
}
