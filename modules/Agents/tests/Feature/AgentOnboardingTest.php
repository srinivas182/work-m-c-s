<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Agents\Support\AgentDemos;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Billing\Models\CashPayment;
use Wurk\Billing\Models\CreditPack;
use Wurk\Identity\Models\Identity;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Security\Support\Settings;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Website\Contracts\SiteTemplates;

/*
 * Sprint 23.4: an agent sets a business up on their phone with the owner beside them — the owner's number verified
 * by a code, a package, setup, the first top-up (online, cash, or later) and the hand-over on WhatsApp.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    Mail::fake();
    $this->createShards(1);
    $this->artisan('website:seed-categories');
    $this->artisan('website:install-templates', ['--only' => ['salon-hair-braids']]);
    $this->package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    CreditPack::query()->create(['name' => 'Small', 'credits' => 100, 'price_cents' => 10000, 'active' => true]);
    $this->messenger = new class implements Messenger
    {
        /** @var list<array<int, mixed>> */
        public array $sent = [];

        public bool $whatsapp = true;

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            $this->sent[] = ['sms', $to, $text];

            return SendResult::sent('s1');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            $this->sent[] = ['whatsapp', $to, $template, $parameters];

            return $this->whatsapp ? SendResult::sent('w1') : SendResult::failed('not approved');
        }

        public function channelActive(string $channel): bool
        {
            return true;
        }
    };
    $this->app->instance(Messenger::class, $this->messenger);
    $this->dineo = app(AgentRoster::class)->create('dineo@example.com', 'Dineo', 'Agent', null, null);
    $this->me = Identity::query()->findOrFail($this->dineo->identity_id);
    $this->a = fn (string $p) => 'http://'.config('wurk.domains.app').'/agent'.$p;
});

it('sets a business up from the owner’s verified number to hand-over, credited to the agent', function () {
    $this->actingAs($this->me)->get(($this->a)('/onboard'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Onboard')->where('onboarding', null));
    $this->actingAs($this->me)->post(($this->a)('/onboard'), ['business_name' => 'Thandi’s Braids', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'mobile' => 'thandi@example.com'])
        ->assertSessionHasErrors(['mobile' => 'Use the owner’s mobile number.']);
    $this->actingAs($this->me)->post(($this->a)('/onboard'), ['business_name' => 'Thandi’s Braids', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'mobile' => '082 555 0101'])->assertRedirect();
    $id = (string) DB::table('agent_onboardings')->value('id');
    $show = ($this->a)('/onboard/'.$id);

    $this->actingAs($this->me)->post($show.'/package', ['package_id' => $this->package->id, 'period' => 'monthly'])->assertSessionHasErrors('step'); // not before the code
    $this->actingAs($this->me)->post($show.'/verify', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($this->me)->post($show.'/verify', ['code' => FakeOtpChannel::$codes['+27825550101']])->assertSessionHasNoErrors();
    $this->actingAs($this->me)->get($show)->assertInertia(fn (Assert $p) => $p->where('onboarding.step', 'package')->where('packages.0.name', 'Starter'));
    $this->actingAs($this->me)->post($show.'/package', ['package_id' => $this->package->id, 'period' => 'monthly'])->assertSessionHasNoErrors();

    $tenant = Tenant::query()->where('handle', 'thandis-braids')->firstOrFail();
    $owner = (string) DB::table('agent_onboardings')->value('owner_identity_id');
    expect(DB::table('agent_businesses')->where('tenant_id', $tenant->id)->first())->toMatchObject(['agent_id' => $this->dineo->id, 'source' => 'agent'])
        ->and(app(Catalog::class)->subscriptionOf($tenant->id)['status'])->toBe('trial');
    $team = fn () => app(TenantContext::class)->run($tenant, fn () => collect(app(TeamDirectory::class)->roster())->mapWithKeys(fn ($m) => [$m['identity_id'] => $m['active']])->sortKeys()->all());
    expect($team())->toEqual([$owner => true, $this->me->id => true]); // the agent helps until hand-over

    // Setup by talking, in the business, comes back here afterwards.
    $this->actingAs($this->me)->get($show)->assertInertia(fn (Assert $p) => $p->where('onboarding.step', 'setup')
        ->where('onboarding.setup_url', '/thandis-braids/website/set-up?return='.rawurlencode('/agent/onboard/'.$id)));
    $this->actingAs($this->me)->get('http://'.config('wurk.domains.app').'/thandis-braids/website/set-up?return=/agent/onboard/'.$id)
        ->assertInertia(fn (Assert $p) => $p->component('Website::SetupTalk')->where('return', '/agent/onboard/'.$id));
    $this->actingAs($this->me)->get('http://'.config('wurk.domains.app').'/thandis-braids/website/set-up?return=//evil.example/x')
        ->assertInertia(fn (Assert $p) => $p->where('return', null));
    $this->actingAs($this->me)->post($show.'/setup-done')->assertSessionHasNoErrors();

    // The first top-up: cash to the agent, counted once WURK approves it.
    $this->actingAs($this->me)->post($show.'/top-up', ['how' => 'cash', 'amount' => 10, 'receipt' => 'R-001'])->assertSessionHasErrors(['amount' => 'Top up at least R20.']);
    $this->actingAs($this->me)->post($show.'/top-up', ['how' => 'cash', 'amount' => 50])->assertSessionHasErrors('receipt');
    $approver = $this->staffWithRole('finance', 'approver@wurk.co.za');
    app(Settings::class)->setPlatform('cash_otp_recipients', [$approver->id], null);
    $this->actingAs($this->me)->post($show.'/top-up', ['how' => 'cash', 'amount' => 50, 'receipt' => 'R-001'])->assertSessionHasNoErrors();
    expect(CashPayment::query()->sole()->only(['tenant_id', 'payer_name', 'receipt_number', 'recorded_by', 'status']))->toEqual(['tenant_id' => $tenant->id, 'payer_name' => 'Thandi Mokoena', 'receipt_number' => 'R-001', 'recorded_by' => $this->me->id, 'status' => 'awaiting_approval']);

    // Hand-over: the owner's links on WhatsApp; the agent's access ends.
    $this->actingAs($this->me)->post($show.'/hand-over')->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $s) => str_starts_with($s, 'Handed over in '));
    expect($this->messenger->sent[0][0])->toBe('whatsapp')->and($this->messenger->sent[0][2])->toBe('wurk_agent_handover')
        ->and($this->messenger->sent[0][3])->toBe(['Thandi’s Braids', 'Thandi', 'https://'.config('wurk.domains.app').'/login', 'https://'.config('wurk.domains.app').'/thandis-braids/website/share'])
        ->and(collect($this->messenger->sent)->where(0, 'sms')->count())->toBe(0)
        ->and($team())->toEqual([$owner => true, $this->me->id => false]);
    $this->actingAs($this->me)->get(($this->a)('/onboard'))->assertInertia(fn (Assert $p) => $p->where('recent.0.step', 'done')->where('recent.0.live', false)->where('recent.0.minutes', 1));
    $this->actingAs($this->me)->post($show.'/hand-over')->assertSessionHasErrors('step'); // once
});

it('lets the owner top up later, falls back to SMS, and keeps each agent to their own', function () {
    $this->messenger->whatsapp = false;
    $this->actingAs($this->me)->post(($this->a)('/onboard'), ['business_name' => 'Glow Salon', 'first_name' => 'Zodwa', 'last_name' => 'Dlamini', 'mobile' => '082 555 0102']);
    $id = (string) DB::table('agent_onboardings')->value('id');
    $show = ($this->a)('/onboard/'.$id);
    $other = app(AgentRoster::class)->create('sipho@example.com', 'Sipho', 'Agent', null, null);
    $this->actingAs(Identity::query()->findOrFail($other->identity_id))->get($show)->assertNotFound();

    $this->actingAs($this->me)->post($show.'/verify', ['code' => FakeOtpChannel::$codes['+27825550102']]);
    $this->actingAs($this->me)->post($show.'/package', ['package_id' => $this->package->id, 'period' => 'monthly']);
    $this->actingAs($this->me)->post($show.'/setup-done');
    $this->actingAs($this->me)->post($show.'/top-up', ['how' => 'later'])->assertSessionHasNoErrors();
    $this->actingAs($this->me)->post($show.'/hand-over')->assertSessionHasNoErrors();
    expect(collect($this->messenger->sent)->firstWhere(0, 'sms')[2])->toContain('Glow Salon is ready, Zodwa. Sign in with your number:');
});

it('turns the agent’s demo into the business, credited as a demo once handed over', function () {
    $cat = app(SiteTemplates::class)->catalogue()[0];
    $demoId = app(AgentDemos::class)->create($this->dineo, 'Thandi’s Braids', $cat['key'], $cat['templates'][0]['key']);
    $this->actingAs($this->me)->get(($this->a)('/onboard'))->assertInertia(fn (Assert $p) => $p->where('demo.business', 'Thandi’s Braids'));
    $this->actingAs($this->me)->post(($this->a)('/onboard'), ['business_name' => 'Thandi’s Braids', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'mobile' => '082 555 0103', 'use_demo' => true]);
    $show = ($this->a)('/onboard/'.DB::table('agent_onboardings')->value('id'));
    $this->actingAs($this->me)->post($show.'/verify', ['code' => FakeOtpChannel::$codes['+27825550103']]);
    $this->actingAs($this->me)->post($show.'/package', ['package_id' => $this->package->id, 'period' => 'monthly'])->assertSessionHasNoErrors();
    expect(Tenant::query()->count())->toBe(1)->and(Tenant::query()->findOrFail($demoId)->handle)->toBe('thandis-braids');
    $this->actingAs($this->me)->post($show.'/setup-done');
    $this->actingAs($this->me)->post($show.'/top-up', ['how' => 'later']);
    $this->actingAs($this->me)->post($show.'/hand-over')->assertSessionHasNoErrors();
    expect(DB::table('agent_businesses')->where('tenant_id', $demoId)->value('source'))->toBe('demo')
        ->and(app(TenantContext::class)->run(Tenant::query()->findOrFail($demoId), fn () => collect(app(TeamDirectory::class)->roster())->firstWhere('identity_id', $this->me->id)['active']))->toBeFalse();
});
