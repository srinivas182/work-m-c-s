<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Agents\Mail\AgentDemoExpiringMail;
use Wurk\Agents\Support\AgentDemos;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Identity\Models\Identity;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Website\Contracts\SiteTemplates;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->dineo = app(AgentRoster::class)->create('dineo@example.com', 'Dineo', 'Agent', null, null);
    $this->me = Identity::query()->findOrFail($this->dineo->identity_id);
    $this->artisan('website:seed-categories');
    $this->artisan('website:install-templates'); // the 24 real industry templates, as production setup loads them
    $cat = app(SiteTemplates::class)->catalogue()[0];
    $this->pick = ['category' => $cat['key'], 'template' => $cat['templates'][0]['key']];
    $this->area = fn (string $p = '') => 'http://'.config('wurk.domains.app').'/agent'.$p;
    $this->make = fn () => $this->actingAs($this->me)->post(($this->area)('/demo'), ['business' => 'Thandi’s Braids', ...$this->pick]);
    $this->demo = fn () => Tenant::query()->where('is_demo', true)->where('status', '!=', 'deleted')->first();
});

it('makes a real business from a template, published at once, with the agent as owner — one at a time', function () {
    ($this->make)()->assertRedirect('/agent/demo');
    $t = ($this->demo)();
    expect($t)->not->toBeNull()->and($t->handle)->toMatch('/^thandis-braids-demo-[a-z0-9]{4}$/')
        ->and(DB::table('memberships')->where('identity_id', $this->me->id)->where('tenant_id', $t->id)->exists())->toBeTrue();
    $site = $this->get('http://'.$t->handle.'.'.config('wurk.domains.root').'/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($site->getContent())->toContain('Demo site made by a WURK agent for Thandi’s Braids')->toContain('<meta name="robots" content="noindex, nofollow">');
    ($this->make)()->assertSessionHasErrors(['template' => 'You already have a demo. Delete it to make another.']);
    $this->actingAs($this->me)->get(($this->area)('/demo'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Demo')->where('demo.business', 'Thandi’s Braids')->where('demo.days_left', 14));
});

it('takes no money and sends no messages from a demo', function () {
    ($this->make)();
    app(TenantContext::class)->run(($this->demo)(), function () {
        $pay = app(BusinessPayments::class);
        expect($pay->available())->toBe([])->and($pay->start('ORDER-1', 10000, 'Test', ['email' => 'c@example.com'], ['return' => '/', 'cancel' => '/', 'notify' => '/']))->toBeNull()
            ->and($pay->eftInstructions())->toBeNull();
        $r = app(Messenger::class)->sms('+27820000000', 'Hello', 'test');
        expect($r->ok)->toBeFalse()->and($r->error)->toBe('This is a demo site — messages are switched off.');
        app(MessageTemplates::class)->email('portal.password', 'c@example.com', ['client' => 'C', 'business' => 'B', 'link' => 'x', 'password' => 'p', 'days' => '7']);
    });
    Mail::assertNothingSent();
});

it('reminds the agent on days 10 and 13 and archives on day 14 — offline, address freed, hidden', function () {
    ($this->make)();
    $t = ($this->demo)();
    $handle = $t->handle;
    $this->travel(10)->days();
    app(AgentDemos::class)->sweep();
    app(AgentDemos::class)->sweep(); // not twice
    Mail::assertSent(AgentDemoExpiringMail::class, 1);
    $this->travel(3)->days();
    app(AgentDemos::class)->sweep();
    Mail::assertSent(AgentDemoExpiringMail::class, 2);
    $this->travel(1)->days();
    $this->travel(1)->minutes();
    $this->artisan('agents:sweep-demos')->assertSuccessful();
    $t->refresh();
    expect($t->status)->toBe('deleted')->and($t->handle)->toBe('archived-'.strtolower($t->id));
    $this->get('http://'.$handle.'.'.config('wurk.domains.root').'/')->assertNotFound(); // old address: no site
    $this->flushSession(); // Dineo's session from 14 days ago has timed out; the admin starts a fresh one
    $admin = $this->staffWithRole('super-admin');
    $this->actingAs($admin)->get($this->admin('/businesses'))->assertInertia(fn (Assert $p) => $p->where('businesses.data', fn ($d) => collect($d)->where('id', $t->id)->isEmpty()));
    $this->actingAs($admin)->get($this->admin('/businesses?status=deleted&kind=demo'))->assertInertia(fn (Assert $p) => $p->where('businesses.data.0.id', $t->id)->where('businesses.data.0.demo', true));
});

it('lets the agent delete the demo and make another', function () {
    ($this->make)();
    $this->actingAs($this->me)->post(($this->area)('/demo/delete'))->assertSessionHas('status', 'Demo deleted. You can make another.');
    expect(Tenant::query()->where('is_demo', true)->where('status', 'deleted')->count())->toBe(1);
    ($this->make)()->assertSessionHasNoErrors();
    expect(($this->demo)())->not->toBeNull();
});
