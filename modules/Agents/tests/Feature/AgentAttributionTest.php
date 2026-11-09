<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Tenancy\Models\Tenant;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    $this->createShards(1);
    $this->package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    $this->ayanda = app(AgentRoster::class)->create('ayanda@example.com', 'Ayanda', 'Agent', null, null);
    $this->bongi = app(AgentRoster::class)->create('bongi@example.com', 'Bongi', 'Agent', null, null);
    $this->root = 'http://'.config('wurk.domains.root');
    // The real signup wizard (as in the Onboarding tests).
    $this->signUp = function (string $contact, string $handle) {
        $app = fn (string $path) => 'http://'.config('wurk.domains.app').$path;
        $this->post($app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'contact' => $contact, 'country' => 'ZA'])->assertSessionHasNoErrors();
        $this->post($app('/signup/verify'), ['code' => FakeOtpChannel::$codes[array_key_last(FakeOtpChannel::$codes)]])->assertSessionHasNoErrors();
        $this->post($app('/signup/package'), ['package_id' => $this->package->id, 'period' => 'monthly'])->assertSessionHasNoErrors();
        $this->post($app('/signup/address'), ['handle' => $handle])->assertSessionHasNoErrors();
        $this->post($app('/signup/complete'))->assertRedirect();

        return Tenant::query()->where('handle', $handle)->sole()->id;
    };
});

it('remembers the agent from their link for 90 days across WURK’s addresses — first link wins; suspended agents’ links do nothing', function () {
    $r = $this->get("{$this->root}/a/{$this->ayanda->code}")->assertRedirect('/');
    $cookie = collect($r->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'wurk_agent');
    expect($cookie->getValue())->toBe($this->ayanda->code)->and($cookie->getDomain())->toBe('.'.config('wurk.domains.root'))
        ->and($cookie->isHttpOnly())->toBeTrue()->and(round(($cookie->getExpiresTime() - time()) / 86400))->toBe(90.0);
    $second = $this->withUnencryptedCookie('wurk_agent', $this->ayanda->code)->get("{$this->root}/a/{$this->bongi->code}");
    expect(collect($second->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'wurk_agent'))->toBeNull(); // first link wins
    $this->bongi->update(['status' => 'suspended']);
    $this->flushHeaders();
    $none = $this->call('GET', "{$this->root}/a/{$this->bongi->code}");
    expect(collect($none->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'wurk_agent'))->toBeNull();
});

it('credits the agent when a business signs up through their link — and nobody otherwise, or for businesses staff create', function () {
    $this->withUnencryptedCookie('wurk_agent', $this->ayanda->code); // as the agent's link left it
    $tenant = ($this->signUp)('thandi@glow.co.za', 'glow-salon');
    expect(DB::table('agent_businesses')->where('tenant_id', $tenant)->first())->toMatchObject(['agent_id' => $this->ayanda->id, 'source' => 'link']);

    $staffMade = Tenant::query()->firstOrFail();
    $admin = Request::create('http://'.config('wurk.domains.admin').'/businesses', 'POST', [], ['wurk_agent' => $this->ayanda->code]);
    DB::table('agent_businesses')->delete();
    app(AgentAttribution::class)->fromSignup($staffMade->id, $admin);
    expect(DB::table('agent_businesses')->count())->toBe(0); // never from the admin address
});

it('signs up without a link: no agent', function () {
    $tenant = ($this->signUp)('lerato@kasi.co.za', 'kasi-threads');
    expect(DB::table('agent_businesses')->where('tenant_id', $tenant)->exists())->toBeFalse();
});

it('lets staff see and correct a business’s agent (logged); finance can look but not change', function () {
    $tenant = $this->createTenant('glow-salon', name: 'Glow Salon');
    $admin = $this->staffWithRole('super-admin');
    $this->actingAs($admin)->get($this->admin("/businesses/{$tenant->id}/agent"))->assertInertia(fn (Assert $p) => $p->component('Agents::Admin/BusinessAgent')->where('current', null));
    $this->actingAs($admin)->put($this->admin("/businesses/{$tenant->id}/agent"), ['agent_id' => $this->bongi->id])->assertSessionHas('status', 'Agent saved.');
    $this->actingAs($admin)->get($this->admin("/businesses/{$tenant->id}/agent"))->assertInertia(fn (Assert $p) => $p->where('current.agent_id', $this->bongi->id)->where('current.source', 'staff'));
    $this->actingAs($admin)->get($this->admin('/agents'))->assertInertia(fn (Assert $p) => $p->where('agents.1.businesses', 1));
    $this->actingAs($admin)->put($this->admin("/businesses/{$tenant->id}/agent"), ['agent_id' => null])->assertSessionHas('status', 'This business no longer has an agent.');
    expect(PlatformAuditEvent::query()->where('action', 'agents.attribution_changed')->count())->toBe(2);
    $finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
    $this->actingAs($finance)->get($this->admin("/businesses/{$tenant->id}/agent"))->assertInertia(fn (Assert $p) => $p->where('canManage', false));
    $this->actingAs($finance)->put($this->admin("/businesses/{$tenant->id}/agent"), ['agent_id' => $this->ayanda->id])->assertForbidden();
});
