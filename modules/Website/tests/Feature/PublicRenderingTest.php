<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
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
        && json_decode((string) $p->input, true)['site']['formAction'] === '/forms/glow-salon/quote');

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
