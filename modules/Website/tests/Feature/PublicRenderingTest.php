<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageRender;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

uses(InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('local');
    $this->artisan('website:install-templates');
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->glow, fn () => app(SiteBuilder::class)->apply('salon-hair-braids'));
    $this->publish = fn () => $this->ctx->run($this->glow, fn () => app(Pages::class)->publish(Page::query()->where('is_home', true)->firstOrFail(), null, null));
    $this->site = 'http://glow-salon.'.config('wurk.domains.root').'/';
});

it('renders a page once at publish, with the same blocks, and serves the stored HTML', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<div class="wurk-site"><h1>Braids that last</h1></div>']))]);
    ($this->publish)();

    Process::assertRan(fn (PendingProcess $p) => str_ends_with((string) end($p->command), 'site-render.js')
        && json_decode((string) $p->input, true)['site']['businessName'] === 'Glow Salon'
        && json_decode((string) $p->input, true)['site']['formAction'] === '/forms/quote');

    $this->get($this->site)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('<h1>Braids that last</h1>', false)->assertSee('<title>Glow Salon</title>', false);
});

it('keeps the live site on its last version when a new render fails', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Version one</h1>']))]);
    ($this->publish)();

    Process::fake(['*' => Process::result(errorOutput: 'renderer crashed', exitCode: 1)]);
    expect(fn () => ($this->publish)())->toThrow(RuntimeException::class);   // the job fails (queued: retried)

    $this->get($this->site)->assertOk()->assertSee('<h1>Version one</h1>', false);
    $this->ctx->run($this->glow, fn () => expect(PageRender::query()->whereNotNull('error')->value('error'))->toContain('renderer crashed'));
});

it('answers 404 for unknown businesses and pages', function () {
    $this->get('http://no-such-biz.'.config('wurk.domains.root').'/')->assertNotFound();
    $this->get($this->site.'no-such-page')->assertNotFound();
});

it('keeps the publishing request’s business context after a render runs inline (sync queue)', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Hi</h1>']))]);
    $this->ctx->run($this->glow, function () {
        app(Pages::class)->publish(Page::query()->where('is_home', true)->firstOrFail(), null, null);
        expect(app(TenantContext::class)->id())->toBe($this->glow->id)
            ->and(PageRender::query()->whereNotNull('path')->count())->toBe(1);
    });
});

it('re-renders every published page when branding or menus change', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Hi</h1>']))]);
    $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        foreach (['home', 'services'] as $slug) {
            $pages->publish(Page::query()->where('slug', $slug)->firstOrFail(), null, null);
        }
        Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>New look</h1>']))]);
        expect($pages->rerenderPublished())->toBe(2);
    });
    Process::assertRanTimes(fn ($p) => true, 4); // two publishes, then two re-renders
    $this->get($this->site)->assertSee('<h1>New look</h1>', false);
});

it('re-renders pages with dated content (a promotion with an end date) each night — and only those', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Hi</h1>']))]);
    $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $doc = json_decode((string) $home->draft, true);
        array_unshift($doc['blocks'], ['id' => 'promo', 'type' => 'PromotionBanner', 'version' => 1, 'props' => [
            'text' => 'Winter special', 'linkLabel' => '', 'linkHref' => '', 'tone' => 'brand', 'starts' => '', 'ends' => '2027-06-30',
        ]]);
        $pages->saveDraft($home, $doc, $home->draft_revision, null);
        $pages->publish($home->refresh(), null, null);                                       // dated
        $pages->publish(Page::query()->where('slug', 'services')->firstOrFail(), null, null); // no dates
    });

    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Re-rendered</h1>']))]);
    $this->artisan('website:rerender-dated')->expectsOutputToContain('1 dated page(s) queued')->assertSuccessful();
    Process::assertRanTimes(fn ($p) => true, 3); // two publishes, then one nightly re-render
    $this->get($this->site)->assertSee('<h1>Re-rendered</h1>', false);
});

it('serves the right status for every situation, always with the security headers', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Glow live</h1>', 'fonts' => []]))]);
    $csp = fn ($r) => $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

    // Not published yet: coming soon, kept out of search engines.
    $csp($this->get($this->site)->assertOk()->assertSee('Coming soon')->assertHeader('X-Robots-Tag', 'noindex'));

    // Published, then renamed: the old address redirects to the new one.
    $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        $pages->publish(Page::query()->where('is_home', true)->firstOrFail(), null, null);
        $services = Page::query()->where('slug', 'services')->firstOrFail();
        $pages->publish($services, null, null);
        $pages->update($services, ['title' => 'Treatments', 'slug' => 'treatments']);
    });
    $csp($this->get($this->site)->assertOk()->assertSee('<h1>Glow live</h1>', false));
    $this->get($this->site.'services')->assertStatus(301)->assertRedirect('/treatments');
    $this->get($this->site.'treatments')->assertOk();
    $this->get($this->site.'no-such-page')->assertNotFound()->assertSee('Glow Salon')->assertSee('Go to the home page');

    // Unpublished: no longer live.
    $this->ctx->run($this->glow, fn () => app(Pages::class)->unpublish(Page::query()->where('slug', 'treatments')->firstOrFail()));
    $this->get($this->site.'treatments')->assertNotFound();

    // Suspended: never the business's content.
    $this->glow->update(['status' => Tenant::STATUS_SUSPENDED]);
    $csp($this->get($this->site)->assertStatus(503)->assertHeader('Retry-After', '3600')->assertSee('Temporarily unavailable')->assertDontSee('Glow live'));
});

it('lets browsers revalidate cheaply, and never hides a new publish behind a cached copy', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>One</h1>', 'fonts' => []]))]);
    ($this->publish)();
    $first = $this->get($this->site)->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public')->assertHeader('Cache-Tag', 'site-'.$this->glow->id);
    $etag = (string) $first->headers->get('ETag');

    $this->get($this->site, ['If-None-Match' => $etag])->assertStatus(304);

    $this->travel(1)->minutes();
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Two</h1>', 'fonts' => []]))]);
    ($this->publish)();
    $this->get($this->site, ['If-None-Match' => $etag])->assertOk()->assertSee('<h1>Two</h1>', false);
});

it('shows a WhatsApp chat button on every page once a WhatsApp number is published — a plain link', function () {
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Hi</h1>', 'fonts' => []]))]);
    ($this->publish)();
    $this->get($this->site)->assertDontSee('wa.me', false);

    $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        $contact = Page::query()->where('slug', 'contact')->firstOrFail();
        $doc = json_decode((string) $contact->draft, true);
        foreach ($doc['blocks'] as &$b) {
            if ($b['type'] === 'Contact') {
                $b['props']['whatsapp'] = '082 555 0101';
            }
        }
        unset($b);
        $pages->saveDraft($contact, $doc, $contact->draft_revision, null);
        $pages->publish($contact->refresh(), null, null);
    });
    $this->get($this->site)->assertSee('href="https://wa.me/27825550101"', false)->assertSee('aria-label="Chat on WhatsApp"', false); // on the home page too
});
