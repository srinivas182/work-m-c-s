<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Identity\Models\Identity;
use Wurk\Onboarding\Models\Signup;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Tenant;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    $this->createShards(1);
    $this->package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
});

/** Walks the wizard to the finish step. */
function signUpTo(object $test, string $contact = 'thandi@glow.co.za', string $handle = 'glow-salon'): void
{
    $app = fn (string $path) => 'http://'.config('wurk.domains.app').$path;
    $test->post($app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'contact' => $contact, 'country' => 'ZA'])->assertSessionHasNoErrors();
    $code = FakeOtpChannel::$codes[array_key_last(FakeOtpChannel::$codes)];
    $test->post($app('/signup/verify'), ['code' => $code])->assertSessionHasNoErrors();
    $test->post($app('/signup/package'), ['package_id' => $test->package->id, 'period' => 'monthly'])->assertSessionHasNoErrors();
    $test->post($app('/signup/address'), ['handle' => $handle])->assertSessionHasNoErrors();
}

it('takes a new owner from signup to a working back office on a free trial', function () {
    $this->get($this->app('/signup?package=starter&period=yearly'))
        ->assertInertia(fn (Assert $p) => $p->component('Onboarding::Signup')->where('step', 'details')->where('data.package_id', $this->package->id)->where('data.period', 'yearly'));

    signUpTo($this);
    $this->post($this->app('/signup/complete'))->assertRedirect('/glow-salon?welcome=1');

    $tenant = Tenant::query()->where('handle', 'glow-salon')->firstOrFail();
    $owner = Identity::query()->where('email', 'thandi@glow.co.za')->firstOrFail();
    $this->assertAuthenticatedAs($owner);
    expect($owner->email_verified_at)->not->toBeNull()
        ->and(TenantSubscription::query()->find($tenant->id)->only(['status', 'billing_period']))->toBe(['status' => 'trial', 'billing_period' => 'monthly'])
        ->and(app(TenantContext::class)->run($tenant, fn () => Gate::forUser($owner)->allows('team.manage')))->toBeTrue()
        ->and(app(TenantContext::class)->run($tenant, fn () => app(CreditWallet::class)->balance()['balance_milli']))->toBe(100000)
        ->and(PlatformAuditEvent::query()->where('action', 'signup.completed')->exists())->toBeTrue();

    $this->get($this->app('/glow-salon'))->assertOk();
});

it('sends the code to a brand-new address, and refuses a wrong code', function () {
    $this->post($this->app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'T', 'last_name' => 'M', 'contact' => '082 123 4567', 'country' => 'ZA']);
    expect(FakeOtpChannel::$codes)->toHaveKey('+27821234567');

    $this->post($this->app('/signup/verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post($this->app('/signup/package'), ['package_id' => $this->package->id, 'period' => 'monthly'])->assertSessionHasErrors('signup');
});

it('refuses temporary mailboxes and taken or reserved web addresses', function () {
    $this->post($this->app('/signup/details'), ['business_name' => 'Xola Store', 'first_name' => 'A', 'last_name' => 'B', 'contact' => 'someone@mailinator.com', 'country' => 'ZA'])->assertSessionHasErrors('contact');
    expect(FakeOtpChannel::$sent)->toBe(0);

    $this->createTenant('taken-name');
    $this->post($this->app('/signup/details'), ['business_name' => 'Xola Store', 'first_name' => 'A', 'last_name' => 'B', 'contact' => 'a@b.co.za', 'country' => 'ZA']);
    $this->post($this->app('/signup/verify'), ['code' => FakeOtpChannel::$codes['a@b.co.za']]);
    $this->post($this->app('/signup/package'), ['package_id' => $this->package->id, 'period' => 'monthly']);
    $this->post($this->app('/signup/address'), ['handle' => 'taken-name'])->assertSessionHasErrors('handle');
    $this->post($this->app('/signup/address'), ['handle' => 'admin'])->assertSessionHasErrors('handle');
    $this->getJson($this->app('/signup/check-handle?handle=taken-name'))->assertJson(['available' => false]);
});

it('gives each email or number one free trial; a second business starts unpaid and goes to billing', function () {
    signUpTo($this);
    $this->post($this->app('/signup/complete'));
    $this->post($this->app('/sign-out'));
    $this->travel(2)->minutes(); // codes to the same address are spaced out

    signUpTo($this, handle: 'glow-salon-two');
    $this->post($this->app('/signup/complete'))->assertRedirect('/glow-salon-two/settings/billing');

    $second = Tenant::query()->where('handle', 'glow-salon-two')->firstOrFail();
    expect(TenantSubscription::query()->find($second->id)->status)->toBe('pending_payment')
        ->and(DB::table('trial_claims')->count())->toBe(1);
    $this->get($this->app('/glow-salon-two'))->assertRedirect(route('tenant.billing', ['tenant' => 'glow-salon-two']));
});

it('never creates two businesses when finish is pressed twice', function () {
    signUpTo($this);
    $signup = Signup::query()->firstOrFail();
    $this->post($this->app('/signup/complete'));

    $this->withSession(['signup.id' => $signup->id])->post($this->app('/signup/complete'))->assertSessionHasErrors('signup');
    expect(Tenant::query()->where('handle', 'glow-salon')->count())->toBe(1)
        ->and(DB::table('trial_claims')->count())->toBe(1);
});

it('resumes an unfinished signup on another device after verifying again', function () {
    $this->post($this->app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'contact' => 'thandi@glow.co.za', 'country' => 'ZA']);
    $this->post($this->app('/signup/verify'), ['code' => FakeOtpChannel::$codes['thandi@glow.co.za']]);
    $this->post($this->app('/signup/package'), ['package_id' => $this->package->id, 'period' => 'yearly']);

    $this->flushSession();
    $this->travel(2)->minutes();
    $this->post($this->app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'contact' => 'thandi@glow.co.za', 'country' => 'ZA']);
    $this->post($this->app('/signup/verify'), ['code' => FakeOtpChannel::$codes['thandi@glow.co.za']]);

    $this->get($this->app('/signup'))->assertInertia(fn (Assert $p) => $p->where('step', 'address')->where('data.period', 'yearly'));
    expect(Signup::query()->count())->toBe(1);
});

it('expires signups after 7 days', function () {
    $this->post($this->app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'T', 'last_name' => 'M', 'contact' => 'thandi@glow.co.za', 'country' => 'ZA']);
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];
    $this->travel(8)->days();
    $this->post($this->app('/signup/verify'), ['code' => $code])->assertSessionHasErrors('signup');
});
