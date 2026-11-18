<?php

declare(strict_types=1);

namespace Wurk\Website\Setup;

use Illuminate\Support\Facades\DB;
use Wurk\Ai\Contracts\AiActionRefused;
use Wurk\Platform\Contracts\SetupStep;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Ai\SiteGenerator;
use Wurk\Website\Contracts\SiteTemplates;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ContentDocument;

/**
 * Setting up by talking (23.1), the website: a design for the business's kind of work (when it has no site yet), its
 * contact details and opening hours exactly as the owner gave them, the services and prices they named, and the text
 * written by the AI site writer (which never invents prices, hours or testimonials). Pages stay drafts until published.
 */
final readonly class WebsiteSetupStep implements SetupStep
{
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(private TenantContext $context, private SiteTemplates $templates, private SiteGenerator $writer) {}

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
        DB::connection($this->context->connectionName())->transaction(function () use ($brief, $hours, $open, $by): void {
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
