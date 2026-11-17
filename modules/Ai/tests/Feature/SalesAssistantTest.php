<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Agents\Mail\AgentLeadMail;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Models\SalesConversation;
use Wurk\Ai\Support\AiSettings;
use Wurk\Marketing\Mail\ContactMessageMail;
use Wurk\Plans\Actions\PackageCatalog;

uses(InteractsWithIdentity::class);

/** Sprint 19.5a: WURK's sales assistant on wurk.co.za. */
beforeEach(function () {
    Mail::fake();
    config(['wurk.contact_email' => 'hello@wurk.test']);
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$script = [];
    SimulatedDriver::$seen = [];
    $make = fn (string $key, string $name, int $cents, int $order) => app(PackageCatalog::class)->create([
        'key' => $key, 'name' => $name, 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => $order,
        'monthly_price_cents' => $cents, 'yearly_price_cents' => $cents * 10, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    $make('starter', 'Starter', 29900, 1);
    $make('growth', 'Growth', 59900, 2);
    $this->root = 'http://'.config('wurk.domains.root');
    $this->on = function (array $over = []) {
        $s = app(AiSettings::class)->all();
        app(AiSettings::class)->save([...$s, 'sales' => [...$s['sales'], 'enabled' => true, ...$over]], null);
    };
    $this->say = fn (string $text, ?string $conversation = null, string $visitor = 'visitor-key-0123456789') => $this->postJson("{$this->root}/_sales/message",
        ['visitor' => $visitor, 'message' => $text, 'conversation' => $conversation])->assertOk()->json();
});

it('is off until HQ switches it on, and then says it’s an AI', function () {
    $this->getJson("{$this->root}/_sales")->assertOk()->assertExactJson(['enabled' => false]);
    expect(($this->say)('Hi')['mode'])->toBe('off')->and(SimulatedDriver::$seen)->toBe([]);
    ($this->on)();
    $c = $this->getJson("{$this->root}/_sales")->assertOk()->json();
    expect($c['greeting'])->toContain('WURK’s AI assistant')->and($c['notice'])->toContain('kept for 30 days');
    $this->postJson("{$this->root}/_sales/message", ['visitor' => 'short', 'message' => 'Hi'])->assertUnprocessable();
});

it('recommends a package from the live prices, opens signup with it chosen, and never invents a price', function () {
    ($this->on)();
    SimulatedDriver::$script = [['tool' => 'packages'], ['text' => 'Starter fits a one-chair salon: R299 a month, excluding VAT, with a 14-day free trial.']];
    $r = ($this->say)('I run a small salon. Which package?');
    expect($r['mode'])->toBe('ai')->and($r['reply'])->toContain('R299')
        ->and(SimulatedDriver::$seen[0]['tools'])->toBe(['packages', 'help_centre', 'start_signup', 'contact_wurk']) // no agent: no agent tool
        ->and(json_encode(SimulatedDriver::$seen[1]['items'], JSON_UNESCAPED_UNICODE))->toContain('"monthly":"R599"');

    SimulatedDriver::$script = [['tool' => 'start_signup', 'arguments' => ['package_key' => 'starter']], ['text' => 'Here you go!']];
    $s = ($this->say)('Great, sign me up', $r['conversation']);
    expect($s['cards'][0])->toMatchArray(['service' => 'Starter', 'price' => 'R299 a month'])
        ->and($s['cards'][0]['action'])->toBe(['label' => 'Start your free trial', 'url' => 'http://'.config('wurk.domains.app').'/signup?package=starter'])
        ->and(collect(SimulatedDriver::$seen[2]['items'])->pluck('content')->filter(fn ($c) => is_string($c))->values()->all())
        ->toContain('I run a small salon. Which package?', 'Great, sign me up'); // the conversation carries on
    expect(SalesConversation::query()->find($r['conversation']))->outcome->toBe('signup')->replies->toBe(2);

    SimulatedDriver::$script = [['text' => 'Pro is only R99 a month!']];
    expect(($this->say)('Anything cheaper?', $r['conversation'])['reply'])->toContain('couldn’t check that properly');
});

it('hands over to WURK through the contact form, with consent', function () {
    ($this->on)();
    SimulatedDriver::$script = [
        ['tool' => 'contact_wurk', 'arguments' => ['name' => 'Lerato', 'contact' => '082 555 0123', 'message' => 'Wants a demo for 3 clinics', 'agreed_to_be_contacted' => false]],
        ['tool' => 'contact_wurk', 'arguments' => ['name' => 'Lerato', 'contact' => '082 555 0123', 'business' => 'Lerato Physio', 'message' => 'Wants a demo for 3 clinics', 'agreed_to_be_contacted' => true]],
        ['text' => 'Sent — someone from WURK will get back to you within one working day.'],
    ];
    $r = ($this->say)('Can someone show me a demo?');
    expect(json_encode(SimulatedDriver::$seen[1]['items'], JSON_UNESCAPED_UNICODE))->toContain('Ask whether they agree to be contacted by WURK');
    expect(DB::table('contact_messages')->sole())->name->toBe('Lerato')->business->toBe('Lerato Physio')
        ->and(SalesConversation::query()->find($r['conversation'])->outcome)->toBe('handed_over');
    Mail::assertSent(ContactMessageMail::class, fn (ContactMessageMail $m) => $m->hasTo('hello@wurk.test') && str_contains($m->data['message'], 'sales assistant chat'));
});

it('passes a visitor to the agent whose link they followed', function () {
    ($this->on)();
    $agent = app(AgentRoster::class)->create('ayanda@example.com', 'Ayanda', 'Dlamini', null, null);
    SimulatedDriver::$script = [['tool' => 'your_agent'], ['tool' => 'your_agent', 'arguments' => ['name' => 'Sipho', 'contact' => 'sipho@example.com', 'message' => 'Help me set up', 'agreed_to_be_contacted' => true]], ['text' => 'Sent. Ayanda will contact you.']];
    $this->withCredentials()->withUnencryptedCookie('wurk_agent', $agent->code)->postJson("{$this->root}/_sales/message", ['visitor' => 'visitor-key-0123456789', 'message' => 'Can someone help me?'])->assertOk();
    expect(SimulatedDriver::$seen[0]['tools'])->toContain('your_agent')
        ->and(json_encode(SimulatedDriver::$seen[1]['items']))->toContain('"agent":"Ayanda"');
    Mail::assertSent(AgentLeadMail::class, fn (AgentLeadMail $m) => $m->hasTo('ayanda@example.com') && $m->contact === 'sipho@example.com');
});

it('stops at HQ’s monthly cap or when WURK AI is down, and forgets conversations after 30 days', function () {
    ($this->on)(['monthly_cap' => 1]);
    SimulatedDriver::$script = [['text' => 'Hello!']];
    $first = ($this->say)('Hi');
    expect($first['mode'])->toBe('ai')->and(($this->say)('Again', $first['conversation'])['mode'])->toBe('off');
    ($this->on)(['monthly_cap' => 100]);
    SimulatedDriver::$failNext = 3;
    expect(($this->say)('Hi', null, 'another-visitor-0123456789')['mode'])->toBe('off');

    $this->travel(31)->days();
    $this->artisan('ai:sweep-sales-conversations')->expectsOutput('Conversations deleted: 2.')->assertSuccessful();
});

it('is set in Admin → AI, with its use this month', function () {
    $staff = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $this->actingAs($staff)->get($this->admin('/settings/ai'))->assertInertia(fn (Assert $p) => $p->where('settings.sales.enabled', false)->where('sales.replies', 0));
    $settings = [...AiSettings::DEFAULTS, 'sales' => ['enabled' => true, 'greeting' => 'Sawubona!', 'monthly_cap' => 500]];
    $this->actingAs($staff)->put($this->admin('/settings/ai'), [...$settings, 'sales' => [...$settings['sales'], 'greeting' => '']])->assertSessionHasErrors('sales.greeting');
    $this->actingAs($staff)->put($this->admin('/settings/ai'), $settings)->assertSessionHas('status', 'WURK AI settings saved.');
    expect($this->getJson("{$this->root}/_sales")->json('greeting'))->toBe('Sawubona!');
});
