<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\ShareKit;
use Wurk\Website\Support\SiteBuilder;

/*
 * Sprint 23.3: the share kit — what's published, as links tagged by where they're shared, WhatsApp Status images, and
 * posters and table cards with the business's QR code — and Reports counting a poster's visits as "Poster".
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Process::fake(['*' => fn (PendingProcess $p) => Process::result(output: (string) json_encode(['html' => '<main></main>', 'fonts' => [],
        'blocks' => array_map(fn ($b) => ['type' => $b['type'], 'props' => $b['props']], json_decode((string) $p->input, true)['document']['blocks'])]))]);
    $this->artisan('website:install-templates', ['--only' => ['salon-hair-braids']]);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner');
        app(SiteBuilder::class)->apply('salon-hair-braids');
    });
    $this->url = fn (string $path) => $this->app('/glow-salon/website/share'.$path);
});

it('offers only what’s published, each link tagged by where it’s shared', function () {
    $this->actingAs($this->owner)->get(($this->url)(''))->assertInertia(fn (Assert $p) => $p->component('Website::ShareKit')->where('items', [])->where('posters', []));

    ($this->in)(function () {
        $pages = app(Pages::class);
        $today = now('Africa/Johannesburg');
        foreach (Page::query()->get() as $page) {
            $doc = json_decode((string) $page->draft, true);
            if ($page->is_home) {
                array_splice($doc['blocks'], 1, 0, [
                    ['id' => 'special', 'type' => 'PromotionBanner', 'version' => 1, 'props' => ['text' => 'Knotless braids R400 this week', 'linkLabel' => 'Book', 'linkHref' => '/contact#book', 'tone' => 'brand', 'starts' => '', 'ends' => $today->copy()->addDays(3)->toDateString()]],
                    ['id' => 'old', 'type' => 'PromotionBanner', 'version' => 1, 'props' => ['text' => 'Last month’s special', 'linkLabel' => '', 'linkHref' => '', 'tone' => 'brand', 'starts' => '', 'ends' => $today->copy()->subDay()->toDateString()]],
                ]);
            }
            $pages->saveDraft($page, $doc, $page->draft_revision, null);
            if ($page->slug !== 'gallery') { // a page that's still a draft isn't offered
                $pages->publish($page->refresh(), null, null);
            }
        }
    });
    $site = 'http://glow-salon.'.config('wurk.domains.root');
    $this->actingAs($this->owner)->get(($this->url)(''))->assertInertia(fn (Assert $p) => $p
        ->where('items', fn ($items) => collect($items)->pluck('key')->all() === ['site', 'special-1', 'book']
            && collect($items)->firstWhere('key', 'book')['links']['poster'] === $site.'/contact?utm_source=poster#book'
            && collect($items)->firstWhere('key', 'special-1')['detail'] === 'Knotless braids R400 this week'
            && collect($items)->firstWhere('key', 'site')['links']['status'] === $site.'/?utm_source=status')
        ->where('posters', [['kind' => 'book', 'label' => 'Scan to book'], ['kind' => 'site', 'label' => 'Scan to visit us']]));
    expect(ShareKit::tagged('https://wa.me/27825550101?text=Hi', 'poster'))->toBe('https://wa.me/27825550101?text=Hi');
});

it('makes a 9:16 WhatsApp Status image and posters and table cards as PDFs', function () {
    ($this->in)(fn () => Page::query()->get()->each(fn (Page $page) => app(Pages::class)->publish($page, null, null)));

    $png = $this->actingAs($this->owner)->get(($this->url)('/status/book'))->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
    expect(getimagesizefromstring((string) $png)[0] ?? 0)->toBe(1080)->and(getimagesizefromstring((string) $png)[1] ?? 0)->toBe(1920);
    $this->actingAs($this->owner)->get(($this->url)('/status/product-nope'))->assertNotFound();

    foreach (['a4', 'a5', 'tent'] as $size) {
        $pdf = $this->actingAs($this->owner)->get(($this->url)('/poster?kind=book&size='.$size))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        expect(str_starts_with((string) $pdf, '%PDF'))->toBeTrue();
    }
    $this->actingAs($this->owner)->get(($this->url)('/poster?kind=order&size=a4'))->assertNotFound(); // no shop
    $this->actingAs($this->owner)->get(($this->url)('/poster?kind=menu&size=a4'))->assertSessionHasErrors('kind');
});

it('counts a visit from a poster’s QR code as “Poster” in Reports', function () {
    ($this->in)(fn () => Page::query()->get()->each(fn (Page $page) => app(Pages::class)->publish($page, null, null)));
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Mobile Safari/537.36')
        ->get('http://glow-salon.'.config('wurk.domains.root').'/contact?utm_source=poster')->assertOk();
    expect(($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('site_visitors')->value('source')))->toBe('Poster');
});
