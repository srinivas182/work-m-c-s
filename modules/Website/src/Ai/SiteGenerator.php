<?php

declare(strict_types=1);

namespace Wurk\Website\Ai;

use Illuminate\Support\Facades\DB;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Support\AiActions;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\ContentDocument;

/**
 * "Write my website": the AI fills only whitelisted text slots on the owner's pages.
 * Testimonials, team, prices, opening hours, qualifications and registrations are
 * never sent and never written — there is nowhere for an invented one to go.
 * Results land in drafts; the owner reviews in the editor and publishes.
 */
final readonly class SiteGenerator
{
    /** Block type → text fields the AI may write, with their limits. */
    public const SLOTS = [
        'Hero' => ['heading' => 70, 'text' => 200],
        'Text' => ['heading' => 60, 'body' => 1500],
        'ImageText' => ['heading' => 60, 'text' => 600],
        'FeatureGrid' => ['heading' => 60, 'intro' => 200],
        'CallToAction' => ['heading' => 70, 'text' => 160],
        'Faq' => ['heading' => 60],
        'ServicesPrices' => ['heading' => 60, 'intro' => 200],
    ];

    public function __construct(private Ai $ai, private AiActions $actions, private TenantContext $context) {}

    /**
     * @param  array{business: string, what: string, area: string, services: list<array{name: string, price?: int|null}>, tone: string}  $brief
     * @return int pages written
     */
    public function generate(array $brief, string $confirmation, ?string $by): int
    {
        $pages = Page::query()->orderBy('position')->get();
        $slots = [];
        foreach ($pages as $page) {
            foreach ((array) (json_decode($page->draft, true)['blocks'] ?? []) as $block) {
                foreach (self::SLOTS[$block['type']] ?? [] as $field => $max) {
                    $slots[$page->slug][$block['id']][$field] = $max;
                }
            }
        }

        return $this->actions->perform('generate_site', $confirmation, function () use ($brief, $slots, $pages, $by): int {
            $answer = $this->ai->run('site_generation', $this->system(), $this->prompt($brief, $slots), 6000, $this->context->id());
            $fills = $this->parse($answer);

            return DB::connection($this->context->connectionName())->transaction(function () use ($fills, $slots, $pages, $brief, $by): int {
                $written = 0;
                foreach ($pages as $page) {
                    $doc = (array) json_decode($page->draft, true);
                    $changed = false;
                    foreach ($doc['blocks'] as &$block) {
                        foreach ($slots[$page->slug][$block['id']] ?? [] as $field => $max) {
                            $value = $fills['pages'][$page->slug][$block['id']][$field] ?? null;
                            if (is_string($value) && trim($value) !== '') {
                                $block['props'][$field] = mb_substr(trim(strip_tags($value)), 0, $max);
                                $changed = true;
                            }
                        }
                        // Services and prices: only what the owner typed — never invented.
                        if ($block['type'] === 'ServicesPrices' && $brief['services'] !== []) {
                            $block['props']['items'] = array_map(fn ($s) => ['name' => mb_substr($s['name'], 0, 60), 'description' => '', 'duration' => '', 'price' => (int) ($s['price'] ?? 0), 'from' => false], $brief['services']);
                            $changed = true;
                        }
                    }
                    unset($block);
                    $seo = $fills['seo'][$page->slug] ?? [];
                    if ($changed || $seo !== []) {
                        $page->update([
                            'draft' => json_encode(ContentDocument::validate($doc)), 'draft_revision' => $page->draft_revision + 1, 'updated_by' => $by,
                            'seo_title' => is_string($seo['title'] ?? null) ? mb_substr(strip_tags($seo['title']), 0, 70) : $page->seo_title,
                            'seo_description' => is_string($seo['description'] ?? null) ? mb_substr(strip_tags($seo['description']), 0, 170) : $page->seo_description,
                        ]);
                        $written++;
                    }
                }

                return $written;
            });
        });
    }

    private function system(): string
    {
        return 'You write warm, clear, professional website copy for small South African businesses in British/South African English. '
            .'Reply with JSON only: {"pages": {"<page>": {"<blockId>": {"<field>": "text"}}}, "seo": {"<page>": {"title": "", "description": ""}}}. '
            .'Fill only the fields listed, within each character limit. Never invent testimonials, reviews, prices, qualifications, registrations, awards, opening hours or statistics. '
            .'If something isn’t in the brief, write around it rather than making it up.';
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, array<string, array<string, int>>>  $slots
     */
    private function prompt(array $brief, array $slots): string
    {
        $services = implode(', ', array_map(fn ($s) => $s['name'], $brief['services']));

        return "Business: {$brief['business']}\nWhat they do: {$brief['what']}\nArea: {$brief['area']}\nServices: {$services}\nTone: {$brief['tone']}\n\n"
            .'SLOTS (page → block id → field → max characters): '.json_encode($slots);
    }

    /** @return array{pages: array<string, mixed>, seo: array<string, mixed>} */
    private function parse(string $answer): array
    {
        $json = trim(preg_replace('/^```(?:json)?|```$/m', '', $answer) ?? '');
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['pages'] ?? null)) {
            throw new AiUnavailable('WURK AI couldn’t write your website just now. Please try again.');
        }

        return ['pages' => $data['pages'], 'seo' => is_array($data['seo'] ?? null) ? $data['seo'] : []];
    }
}
