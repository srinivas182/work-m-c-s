<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;

/**
 * The steps from a template to a site the owner is proud of — each judged from the site's
 * real state; the few that can't be (opening hours, legal pages, "Ask us" prices) are confirmed.
 */
final readonly class LaunchChecklist
{
    /** Steps the owner confirms themselves. */
    public const CONFIRMABLE = ['prices', 'hours', 'legal'];

    public function __construct(private Pages $pages) {}

    /** @return list<array{key: string, label: string, hint: string, done: bool, href: string, confirm: bool}> */
    public function steps(): array
    {
        $site = $this->pages->ensureSite();
        $confirmed = (array) ($site->settings['checklist'] ?? []);
        $pages = Page::query()->orderBy('position')->get();
        $blocks = $pages->flatMap(fn (Page $p) => (array) (json_decode($p->draft, true)['blocks'] ?? []));
        $base = route('tenant.website.pages', [], false);
        $edit = fn (?Page $p) => $p === null ? $base : route('tenant.website.editor', ['page' => $p->id], false);
        $home = $pages->firstWhere('is_home', true);
        $find = fn (string $type) => $pages->first(fn (Page $p) => collect((array) (json_decode($p->draft, true)['blocks'] ?? []))->contains('type', $type));

        $contact = $blocks->where('type', 'Contact')->contains(fn ($b) => trim((string) ($b['props']['phone'] ?? '').($b['props']['whatsapp'] ?? '').($b['props']['email'] ?? '')) !== '');
        $priced = $blocks->where('type', 'ServicesPrices')->contains(fn ($b) => collect((array) ($b['props']['items'] ?? []))->contains(fn ($i) => (int) ($i['price'] ?? 0) > 0));
        $standIns = $pages->contains(fn (Page $p) => str_contains((string) $p->draft, '\/images\/templates\/') || str_contains((string) $p->draft, '/images/templates/'));

        return [
            ['key' => 'logo', 'label' => 'Add your logo', 'hint' => 'And check your colours.', 'done' => ! empty($site->branding['logo']), 'href' => route('tenant.website.branding', [], false), 'confirm' => false],
            ['key' => 'contact', 'label' => 'Add your contact details', 'hint' => 'Phone, WhatsApp or email — so customers can reach you.', 'done' => $contact, 'href' => $edit($find('Contact') ?? $home), 'confirm' => false],
            ['key' => 'prices', 'label' => 'Add your prices', 'hint' => 'Or confirm that “Ask us” is what you want.', 'done' => $priced || ! empty($confirmed['prices']), 'href' => $edit($find('ServicesPrices') ?? $home), 'confirm' => true],
            ['key' => 'photos', 'label' => 'Use your own photos', 'hint' => 'Replace every sample photo with one of yours.', 'done' => ! $standIns, 'href' => $edit($home), 'confirm' => false],
            ['key' => 'hours', 'label' => 'Check your opening hours', 'hint' => 'Sample hours are filled in — make them yours.', 'done' => ! empty($confirmed['hours']), 'href' => $edit($find('OpeningHours') ?? $home), 'confirm' => true],
            ['key' => 'legal', 'label' => 'Review your legal pages', 'hint' => 'Privacy, terms and cookies are templates, not legal advice — read them before you publish.', 'done' => ! empty($confirmed['legal']), 'href' => $edit($pages->firstWhere('slug', 'privacy') ?? $home), 'confirm' => true],
            ['key' => 'publish', 'label' => 'Publish your website', 'hint' => 'You can keep editing afterwards.', 'done' => $home?->published_version_id !== null, 'href' => $edit($home), 'confirm' => false],
        ];
    }

    public function confirm(string $step, bool $done, ?string $by): void
    {
        $site = $this->pages->ensureSite();
        $checklist = (array) ($site->settings['checklist'] ?? []);
        $checklist[$step] = $done;
        $site->update(['settings' => [...$site->settings, 'checklist' => $checklist]]);
    }
}
