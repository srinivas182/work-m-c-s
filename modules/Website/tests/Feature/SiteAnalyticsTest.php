<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactRemembered;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\SiteTemplates;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';

beforeEach(function () {
    $this->createShards(1);
    $this->artisan('website:seed-categories');
    $this->artisan('website:install-templates');
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $cat = app(SiteTemplates::class)->catalogue()[0];
    $this->ctx->run($this->glow, function () use ($cat) {
        app(SiteTemplates::class)->apply($cat['templates'][0]['key']);
        app(SiteTemplates::class)->publishAll('Test');
    });
    $this->site = 'http://glow-salon.'.config('wurk.domains.root');
    $this->visit = fn (string $path, string $ua, array $headers = []) => $this->flushHeaders()->withHeaders(['User-Agent' => $ua, ...$headers])->get($this->site.$path); // headers don't carry over
    $this->db = fn () => DB::connection($this->ctx->connectionName());
});

it('counts visitors once a day without cookies, with where they came from — and skips bots and prefetches', function () {
    $first = ($this->visit)('/', CHROME, ['Referer' => 'https://www.google.co.za/search?q=salon'])->assertOk();
    expect($first->headers->getCookies())->toBe([]);                                        // no cookie
    ($this->visit)('/', CHROME, ['If-None-Match' => (string) $first->headers->get('ETag')])->assertStatus(304); // a revisit still counts
    ($this->visit)('/', FIREFOX);                                                            // another visitor: Direct
    ($this->visit)('/?utm_source=facebook', CHROME.' Edge');                                 // a third, from a campaign
    ($this->visit)('/', 'Googlebot/2.1 (+http://www.google.com/bot.html)');                 // not people
    ($this->visit)('/', 'WhatsApp/2.23.20');
    ($this->visit)('/', CHROME.' Prefetcher', ['Sec-Purpose' => 'prefetch']);
    $this->ctx->run($this->glow, function () {
        expect((int) ($this->db)()->table('site_page_views')->where('path', '/')->value('views'))->toBe(4)
            ->and(($this->db)()->table('site_visitors')->pluck('source')->sort()->values()->all())->toBe(['Direct', 'Facebook', 'Google'])
            ->and(Schema::connection($this->ctx->connectionName())->hasColumn('site_visitors', 'ip'))->toBeFalse();  // no IP stored
    });
    $yesterday = $this->ctx->run($this->glow, fn () => ($this->db)()->table('site_visitors')->where('source', 'Google')->value('hash'));
    $this->travel(1)->days();
    ($this->visit)('/', CHROME, ['Referer' => 'https://www.google.co.za/']);
    expect($this->ctx->run($this->glow, fn () => ($this->db)()->table('site_visitors')->where('day', now($this->ctx->run($this->glow, fn () => $this->ctx->current()->timezone()))->toDateString())->value('hash')))
        ->not->toBe($yesterday);                                                             // not traceable across days
});

it('credits orders made on the site to where the visitor came from — never back-office orders — and reports it all', function () {
    ($this->visit)('/', CHROME, ['Referer' => 'https://www.google.co.za/']);
    ($this->visit)('/', FIREFOX);
    $as = fn (string $host, string $ua) => app()->instance('request', Request::create('http://'.$host.'/cart/checkout', 'POST', server: ['HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '127.0.0.1']));
    $this->ctx->run($this->glow, function () use ($as) {
        $as('glow-salon.'.config('wurk.domains.root'), CHROME);
        event(new ContactRemembered('c1', true, 'order', 'order-1'));               // on the site: the Google visitor
        $as((string) config('wurk.domains.app'), CHROME);
        event(new ContactRemembered('c2', true, 'order', 'order-2'));                 // in the back office: not a website sale
        expect(($this->db)()->table('site_conversions')->pluck('source', 'source_id')->all())->toBe(['order-1' => 'Google']);
    });
    $owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($owner, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));
    $site = collect($this->actingAs($owner)->get($this->app('/glow-salon/reports'))->viewData('page')['props']['sections'])->firstWhere('key', 'website');
    expect(array_column($site['metrics'], 'value', 'label'))->toBe(['Visitors' => 2, 'Page views' => 2, 'Orders and bookings from the site' => 1])
        ->and($site['rows']['data'])->toContain(['Google', '1', '1'])->toContain(['Direct', '1', '0'])
        ->and($site['highlights'])->toContain('Visitors from Google made the most orders and bookings.');
});
