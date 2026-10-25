<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\LaunchChecklist;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

uses(InteractsWithShards::class);

beforeEach(function () {
    // Like the real renderer: report each block's final values, filling the hours section's defaults
    // (Monday–Friday 08:00–17:00, Saturday 08:00–13:00, Sunday closed) when the template leaves them out.
    Process::fake(['*' => function (PendingProcess $process) {
        $blocks = array_map(function (array $b) {
            if ($b['type'] === 'OpeningHours' && ! isset($b['props']['hours'])) {
                $b['props']['hours'] = [...array_fill(0, 5, ['opens' => '08:00', 'closes' => '17:00']), ['opens' => '08:00', 'closes' => '13:00'], ['opens' => '', 'closes' => '']];
            }

            return ['type' => $b['type'], 'props' => $b['props']];
        }, json_decode((string) $process->input, true)['document']['blocks']);

        return Process::result(output: (string) json_encode(['html' => '<main></main>', 'fonts' => [], 'blocks' => $blocks]));
    }]);
    $this->artisan('website:install-templates');
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->base = 'http://glow-salon.'.config('wurk.domains.root');
    $this->ctx->run($this->glow, fn () => app(SiteBuilder::class)->apply('salon-hair-braids'));
    $this->launch = fn () => $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        foreach (Page::query()->get() as $page) {
            $doc = json_decode((string) $page->draft, true);
            foreach ($doc['blocks'] as &$b) {
                if ($b['type'] === 'Contact') {
                    $b['props']['phone'] = '082 555 0101';
                }
                if ($b['type'] === 'ServicesPrices' && $b['props']['items'] !== []) {
                    $b['props']['items'][0]['price'] = 45000;
                    $b['props']['items'][0]['name'] = 'Knotless braids </script><script>alert(1)</script>';
                    $b['props']['items'][] = ['name' => 'Consultation', 'description' => '', 'duration' => '', 'price' => 0, 'from' => false];
                }
            }
            unset($b);
            $pages->saveDraft($page, $doc, $page->draft_revision, null);
            $pages->publish($page->refresh(), null, null);
        }
    });
    $this->jsonLd = function (): array {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $this->get($this->base.'/')->getContent(), $m);

        return json_decode($m[1], true);
    };
});

it('asks not to be indexed, and offers no sitemap or llms.txt, until the site is live', function () {
    $this->get($this->base.'/robots.txt')->assertOk()->assertSee('Disallow: /', false);
    $this->get($this->base.'/sitemap.xml')->assertNotFound();
    $this->get($this->base.'/llms.txt')->assertNotFound();
});

it('describes the business from what the owner published — no prices for “ask us”, no unconfirmed hours', function () {
    ($this->launch)();
    $this->get($this->base.'/robots.txt')->assertSee('Sitemap: '.$this->base.'/sitemap.xml', false);
    $this->get($this->base.'/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.$this->base.'/</loc>', false)->assertSee('<loc>'.$this->base.'/services</loc>', false);

    $home = (string) $this->get($this->base.'/')->getContent();
    expect($home)->toContain('<link rel="canonical" href="'.$this->base.'/">')
        ->toContain('<meta property="og:title" content="Glow Salon">')
        ->toContain('<meta property="og:image" content="'.$this->base.'/images/templates/salon-hair-braids/')
        ->not->toContain('</script><script>alert(1)'); // owner text can't break out of the data block

    $ld = ($this->jsonLd)();
    $offers = collect($ld['hasOfferCatalog']['itemListElement']);
    expect($ld['@type'])->toBe('LocalBusiness')->and($ld['name'])->toBe('Glow Salon')->and($ld['telephone'])->toBe('082 555 0101')
        ->and($ld)->not->toHaveKey('openingHoursSpecification')                                // sample hours, not confirmed
        ->and($offers->firstWhere('itemOffered.name', 'Consultation'))->not->toHaveKey('price')   // "ask us", not free
        ->and($offers->first()['price'])->toBe('450.00')->and($offers->first()['priceCurrency'])->toBe('ZAR');

    $names = $offers->pluck('itemOffered.name')->map(fn ($n) => mb_strtolower($n));
    expect($names->count())->toBe($names->unique()->count()); // each service once, even when two pages list it
    $this->get($this->base.'/llms.txt')->assertOk()->assertSee('# Glow Salon')->assertSee('- Consultation — ask for a price')
        ->assertSee('— R450')->assertSee('- Phone: 082 555 0101')->assertDontSee('## Opening hours');
});

it('includes opening hours once the owner confirms them, leaving out closed days', function () {
    ($this->launch)();
    $this->ctx->run($this->glow, fn () => app(LaunchChecklist::class)->confirm('hours', true, null));
    $days = collect(($this->jsonLd)()['openingHoursSpecification'])->pluck('dayOfWeek')->map(fn ($d) => basename($d));
    expect($days)->toContain('Monday')->not->toContain('Sunday');
    $this->get($this->base.'/llms.txt')->assertSee('## Opening hours')->assertSee('- Sunday: closed');
});
