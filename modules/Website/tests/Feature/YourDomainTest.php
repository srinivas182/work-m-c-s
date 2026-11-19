<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Domains\CustomDomainManager;
use Wurk\Tenancy\Models\TenantDomain;

/*
 * Sprint 17.3: an owner connects their own domain. One CNAME record; Cloudflare for SaaS issues the certificate; once
 * it works it can be the main address and the WURK address sends page visits there (never payment notices). If the
 * domain stops pointing at WURK, the WURK address is the main one again and the owner is told.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    config(['wurk.custom_domains.cloudflare_token' => 'cf-test-token', 'wurk.custom_domains.cloudflare_zone' => 'zone123',
        'wurk.custom_domains.target' => 'sites.wurk.test', 'wurk.custom_domains.origin' => 'origin.wurk.test']);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->other = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->package = fn (bool $domain) => app(PackageCatalog::class)->create([
        'key' => $domain ? 'growth' : 'starter', 'name' => $domain ? 'Growth' : 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => $domain, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, ($this->package)(true)->id, 'monthly', allowTrial: true);
    app(Catalog::class)->subscribe($this->other->id, ($this->package)(false)->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Zodwa', 'owner'));
    $this->page = $this->app('/glow-salon/website/domain');
    $this->cf = ['ch-1' => ['status' => 'pending', 'ssl' => 'initializing', 'errors' => []]];
    Http::fake(['api.cloudflare.com/*' => function (HttpRequest $r) {
        $id = basename(parse_url($r->url(), PHP_URL_PATH));
        if ($r->method() === 'POST') {
            return Http::response(['success' => true, 'result' => ['id' => 'ch-1', 'hostname' => $r['hostname'], 'status' => 'pending', 'ssl' => ['status' => 'initializing']]]);
        }
        if (! isset($this->cf[$id])) {
            return Http::response(['success' => false, 'errors' => [['code' => 1436, 'message' => 'not found']]], 404);
        }
        if ($r->method() === 'DELETE') {
            unset($this->cf[$id]);

            return Http::response(['success' => true, 'result' => ['id' => $id]]);
        }
        $h = $this->cf[$id];

        return Http::response(['success' => true, 'result' => ['id' => $id, 'status' => $h['status'], 'ssl' => ['status' => $h['ssl'], 'validation_errors' => $h['errors']]]]);
    }]);
    $this->pushed = [];
    $this->app->instance(PushNotifications::class, new class($this) implements PushNotifications
    {
        public function __construct(private $test) {}

        public function notify(string $kind, string $title, string $body, string $url, ?string $permission = null, array $also = [], ?string $except = null, ?string $branchId = null): void
        {
            $this->test->pushed[] = [$kind, $title, $body];
        }
    });
    $this->site = 'http://glow-salon.'.config('wurk.domains.root');
    $this->domain = fn () => TenantDomain::query()->where('type', 'custom')->sole();
});

it('takes the owner’s domain, asks Cloudflare for its certificate, and shows the one record to add', function () {
    $this->actingAs($this->owner)->post($this->page, ['host' => 'glow-salon.wurk.test'])->assertSessionHasErrors(['host' => 'That’s a WURK address. Type a domain you own.']);
    $this->actingAs($this->owner)->post($this->page, ['host' => 'not a domain'])->assertSessionHasErrors(['host' => 'Type your domain like this: www.yourbusiness.co.za']);
    $this->actingAs($this->owner)->post($this->page, ['host' => 'https://WWW.GlowSalon.co.za/book'])->assertSessionHas('status');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/custom_hostnames'
        && $r['hostname'] === 'www.glowsalon.co.za' && $r['custom_origin_server'] === 'origin.wurk.test' && $r['ssl']['method'] === 'http'
        && $r->hasHeader('Authorization', 'Bearer cf-test-token'));
    $this->actingAs($this->owner)->post($this->page, ['host' => 'www.glowsalon.co.za'])->assertSessionHasErrors(['host' => 'You’ve added that domain already.']);

    $this->actingAs($this->owner)->get($this->page)->assertInertia(fn (Assert $p) => $p->component('Website::Domain')->where('allowed', true)->where('available', true)
        ->where('target', 'sites.wurk.test')->where('wurk_address', 'glow-salon.wurk.test')
        ->where('domains.0.host', 'www.glowsalon.co.za')->where('domains.0.status', 'pending')->where('domains.0.primary', false));
    expect(app(TenantContext::class)->run($this->glow, fn () => DB::connection(app(TenantContext::class)->connectionName())->table('audit_events')
        ->where('action', 'website.domain_added')->where('tenant_id', $this->glow->id)->exists()))->toBeTrue();

    // A business without the custom-domain package can't.
    $other = $this->person(['phone' => '+27831000009', 'email' => 'owner@kasi.co.za']);
    $this->member($other, $this->other);
    app(TenantContext::class)->run($this->other, fn () => app(Team::class)->add($other->id, 'Sipho', 'owner'));
    $this->actingAs($other)->get($this->app('/kasi-threads/website/domain'))->assertInertia(fn (Assert $p) => $p->where('allowed', false));
    $this->actingAs($other)->post($this->app('/kasi-threads/website/domain'), ['host' => 'www.kasithreads.co.za'])->assertForbidden();
});

it('goes live once it points at WURK, becomes the main address, and the WURK address sends page visits there', function () {
    $this->actingAs($this->owner)->post($this->page, ['host' => 'www.glowsalon.co.za']);
    $this->actingAs($this->owner)->post($this->page.'/'.($this->domain)()->id.'/primary')->assertSessionHasErrors('domain'); // not working yet

    $this->cf['ch-1'] = ['status' => 'active', 'ssl' => 'active', 'errors' => []];
    $this->artisan('domains:check')->expectsOutputToContain('1 active')->assertSuccessful();
    expect(($this->domain)()->status)->toBe('active')->and(($this->domain)()->verified_at)->not->toBeNull()
        ->and($this->pushed)->toBe([['website', 'Your domain works', 'www.glowsalon.co.za now shows your website, with its own certificate.']]);

    $this->actingAs($this->owner)->post($this->page.'/'.($this->domain)()->id.'/primary')->assertSessionHas('status', 'www.glowsalon.co.za is now your site’s main address.');
    $this->get($this->site.'/?from=ad')->assertStatus(301)->assertRedirect('https://www.glowsalon.co.za/?from=ad');
    expect($this->post($this->site.'/payments/notify/payfast', [])->getStatusCode())->not->toBe(301); // payment notices stay put
    $this->get('http://www.glowsalon.co.za/')->assertOk()->assertSee('Glow Salon'); // the domain itself serves the site
});

it('falls back to the WURK address when the domain stops pointing at WURK, and tells the owner', function () {
    $this->actingAs($this->owner)->post($this->page, ['host' => 'www.glowsalon.co.za']);
    $this->cf['ch-1'] = ['status' => 'active', 'ssl' => 'active', 'errors' => []];
    $this->artisan('domains:check');
    $this->actingAs($this->owner)->post($this->page.'/'.($this->domain)()->id.'/primary');

    $this->cf['ch-1'] = ['status' => 'moved', 'ssl' => 'pending_validation', 'errors' => []];
    $this->artisan('domains:check', ['--all' => true])->expectsOutputToContain('1 lapsed');
    expect(($this->domain)()->only(['status', 'is_primary']))->toBe(['status' => 'lapsed', 'is_primary' => false])
        ->and(($this->domain)()->problem)->toBe('Your domain doesn’t point at WURK any more. Put back the CNAME record shown below.')
        ->and($this->pushed[1][1])->toBe('Your domain stopped working')
        ->and(TenantDomain::query()->where('type', 'subdomain')->where('tenant_id', $this->glow->id)->value('is_primary'))->toBeTrue();
    expect($this->get($this->site.'/')->getStatusCode())->not->toBe(301); // the site is back on its WURK address
});

it('lets go of a domain that never pointed at WURK, and removes one the owner no longer wants', function () {
    $this->actingAs($this->owner)->post($this->page, ['host' => 'www.glowsalon.co.za']);
    $this->travel(8)->days();
    $this->artisan('domains:check')->expectsOutputToContain('1 expired');
    expect(TenantDomain::query()->where('type', 'custom')->count())->toBe(0)->and($this->cf)->toBe([])
        ->and($this->pushed[0][1])->toBe('Your domain wasn’t connected');

    $this->cf = ['ch-1' => ['status' => 'pending', 'ssl' => 'initializing', 'errors' => []]];
    $this->actingAs($this->owner)->post($this->page, ['host' => 'glowsalon.co.za']);
    $this->actingAs($this->owner)->delete($this->page.'/'.($this->domain)()->id)->assertSessionHas('status', 'Removed. Your site is still on your WURK address.');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/custom_hostnames/ch-1'));
    expect(TenantDomain::query()->where('type', 'custom')->count())->toBe(0);
});

it('tells the owner what’s still needed, and HQ sees every domain', function () {
    $this->actingAs($this->owner)->post($this->page, ['host' => 'www.glowsalon.co.za']);
    $this->cf['ch-1'] = ['status' => 'pending', 'ssl' => 'pending_validation', 'errors' => ['The CNAME record isn’t there yet.']];
    $this->actingAs($this->owner)->post($this->page.'/'.($this->domain)()->id.'/check')->assertSessionHas('status', 'Not yet — see below for what’s still needed.');
    expect(($this->domain)()->problem)->toBe('Not working yet: The CNAME record isn’t there yet.');

    $staff = $this->staffWithRole('support', 'support@wurk.co.za');
    $this->actingAs($staff)->get($this->admin('/domains'))->assertInertia(fn (Assert $p) => $p->component('Tenancy::Admin/Domains')
        ->where('configured', true)->where('domains.0.host', 'www.glowsalon.co.za')->where('domains.0.business', 'Glow Salon')->where('counts.pending', 1));
});

it('reads a domain the way owners type it', function (string $typed, ?string $host) {
    expect(CustomDomainManager::normalise($typed))->toBe($host);
})->with([
    ['www.GlowSalon.co.za', 'www.glowsalon.co.za'],
    ['https://glowsalon.co.za/', 'glowsalon.co.za'],
    ['glowsalon.co.za.', 'glowsalon.co.za'],
    ['glow salon', null],
    ['localhost', null],
    ['-bad-.co.za', null],
    ['192.168.1.1', null],
]);

it('never looks up a domain for health checks or the load balancer’s IP address', function () {
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->get('http://10.40.16.5/health/live')->assertOk();
    $this->get('http://localhost/health/live')->assertOk();
    expect(collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'tenant_domains'))->all())->toBe([]);
});
