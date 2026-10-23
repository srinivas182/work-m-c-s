<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Support\Templates;

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

it('limits signups per person, with only a high backstop per shared IP address', function () {
    // South African mobile networks put many customers behind one IP address.
    $request = Request::create('/signup', 'GET', server: ['REMOTE_ADDR' => '41.0.0.7']);
    $request->setLaravelSession($session = app('session')->driver());
    $session->setId(str_repeat('a', 40));

    $limits = collect(RateLimiter::limiter('signup')($request))->map(fn ($l) => [$l->key, $l->maxAttempts, $l->decaySeconds])->all();
    expect($limits)->toBe([['signup:session:'.str_repeat('a', 40), 30, 60], ['signup:ip:41.0.0.7', 300, 60]]);

    $start = collect(RateLimiter::limiter('signup-start')($request))->map(fn ($l) => [$l->key, $l->maxAttempts, $l->decaySeconds])->all();
    expect($start)->toBe([['signup-start:session:'.str_repeat('a', 40), 5, 3600], ['signup-start:ip:41.0.0.7', 60, 3600]]);
});

it('explains a rate limit on the same screen instead of showing a raw 429', function () {
    $handler = app(ExceptionHandler::class);
    $screen = Request::create('/signup/address', 'POST', server: ['HTTP_X_INERTIA' => 'true', 'HTTP_REFERER' => $this->app('/signup')]);
    $screen->setLaravelSession(app('session')->driver());
    app()->instance('request', $screen);

    $response = $handler->render($screen, new ThrottleRequestsException);
    expect($response->getStatusCode())->toBe(302)
        ->and($response->getSession()?->get('warning') ?? session('warning'))->toContain('wait a minute');

    $json = Request::create('/signup/check-handle', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    expect($handler->render($json, new ThrottleRequestsException)->getStatusCode())->toBe(429);
});

/** Publishes one salon template so signup offers the category and design steps. */
function publishSalonTemplate(): void
{
    test()->artisan('website:seed-categories');
    $templates = app(Templates::class);
    $template = $templates->create(SiteCategory::query()->where('key', 'salon-beauty')->firstOrFail(), 'salon-hair', 'Hair & braids salon', 'Bold and warm', null, null);
    $text = fn (string $heading) => ['schema' => 1, 'blocks' => [['id' => 'b1', 'type' => 'Text', 'version' => 1, 'props' => ['heading' => $heading]]]];
    $templates->saveDraft($template, [
        'pages' => [
            ['key' => 'home', 'title' => 'Home', 'slug' => 'home', 'is_home' => true, 'content' => $text('Braids that last')],
            ['key' => 'prices', 'title' => 'Prices', 'slug' => 'prices', 'is_home' => false, 'content' => $text('Our prices')],
        ],
        'menus' => ['header' => [['label' => 'Prices', 'type' => 'page', 'page' => 'prices']], 'footer' => []],
        'branding' => ['primary' => '#9D174D', 'font_pairing' => 'classic', 'corners' => 'soft'],
    ], 1);
    $templates->publish($template->fresh(), null, null);
}

it('lets a new owner choose a category and design, and builds their website from it', function () {
    publishSalonTemplate();
    $app = fn (string $p) => $this->app($p);
    $this->post($app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'contact' => 'thandi@glow.co.za', 'country' => 'ZA']);
    $this->post($app('/signup/verify'), ['code' => FakeOtpChannel::$codes[array_key_last(FakeOtpChannel::$codes)]]);
    $this->post($app('/signup/package'), ['package_id' => $this->package->id, 'period' => 'monthly']);

    $this->get($app('/signup'))->assertInertia(fn (Assert $p) => $p->where('step', 'category')->where('steps', Signup::STEPS_WITH_TEMPLATES)->where('catalogue.0.key', 'salon-beauty'));
    $this->post($app('/signup/category'), ['category' => 'health'])->assertSessionHasErrors('category'); // not offered: no template yet
    $this->post($app('/signup/category'), ['category' => 'salon-beauty'])->assertSessionHasNoErrors();
    $this->post($app('/signup/template'), ['template' => 'someone-elses'])->assertSessionHasErrors('template');
    $this->post($app('/signup/template'), ['template' => 'salon-hair'])->assertSessionHasNoErrors();
    $this->get($app('/signup'))->assertInertia(fn (Assert $p) => $p->where('step', 'address'));
    $this->post($app('/signup/address'), ['handle' => 'glow-salon']);
    $this->post($app('/signup/complete'))->assertRedirect('/glow-salon?welcome=1');

    $tenant = Tenant::query()->where('handle', 'glow-salon')->firstOrFail();
    app(TenantContext::class)->run($tenant, function () {
        expect(Site::query()->firstOrFail()->only(['category_key', 'template_key']))->toBe(['category_key' => 'salon-beauty', 'template_key' => 'salon-hair'])
            ->and(Page::query()->orderBy('position')->pluck('slug')->all())->toBe(['home', 'prices']);
    });
});

it('never blocks signup when the chosen template disappears before finishing', function () {
    publishSalonTemplate();
    $app = fn (string $p) => $this->app($p);
    $this->post($app('/signup/details'), ['business_name' => 'Glow Salon', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'contact' => 'thandi@glow.co.za', 'country' => 'ZA']);
    $this->post($app('/signup/verify'), ['code' => FakeOtpChannel::$codes[array_key_last(FakeOtpChannel::$codes)]]);
    $this->post($app('/signup/package'), ['package_id' => $this->package->id, 'period' => 'monthly']);
    $this->post($app('/signup/category'), ['category' => 'salon-beauty']);
    $this->post($app('/signup/template'), ['template' => 'salon-hair']);
    $this->post($app('/signup/address'), ['handle' => 'glow-salon']);

    app(Templates::class)->retire(SiteTemplate::query()->where('key', 'salon-hair')->firstOrFail(), null);
    $this->post($app('/signup/complete'))->assertRedirect('/glow-salon?welcome=1'); // the business exists; the starter layout is used

    expect(Tenant::query()->where('handle', 'glow-salon')->exists())->toBeTrue();
});

it('previews published templates publicly, never drafts', function () {
    publishSalonTemplate();
    $this->get($this->app('/templates/salon-hair/preview'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Website::TemplatePreview')->where('page.key', 'home')->where('menus.header.0.href', '/templates/salon-hair/preview?page=prices'));
    $this->get($this->app('/templates/salon-hair/preview?page=prices'))->assertInertia(fn (Assert $p) => $p->where('content.blocks.0.props.heading', 'Our prices'));

    $draft = app(Templates::class)->create(SiteCategory::query()->where('key', 'health')->firstOrFail(), 'dental', 'Dental practice', null, null, null);
    $this->get($this->app('/templates/dental/preview'))->assertNotFound();
});
