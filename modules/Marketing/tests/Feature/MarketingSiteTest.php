<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Wurk\Marketing\Mail\ContactMessageMail;
use Wurk\Plans\Actions\PackageCatalog;

function root(string $path = '/'): string
{
    return 'http://'.config('wurk.domains.root').$path;
}

function makePackage(string $key, string $visibility, string $status = 'active'): void
{
    $package = app(PackageCatalog::class)->create([
        'key' => $key, 'name' => ucfirst($key), 'type' => 'self_managed', 'visibility' => $visibility, 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['bookings'],
    ], null);
    if ($status === 'archived') {
        app(PackageCatalog::class)->archive($package, true);
    }
}

it('shows only public packages on sale, with module names and signup links', function () {
    makePackage('starter', 'public');
    makePackage('vip', 'private');
    makePackage('legacy', 'public', 'archived');

    $this->get(root('/pricing'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Marketing::Pricing')
        ->has('packages', 1)->where('packages.0.key', 'starter')->where('moduleNames.bookings', 'Appointments')
        ->where('signupUrl', 'https://'.config('wurk.domains.app').'/signup'));
});

it('serves every marketing page and only known legal pages', function () {
    foreach (['/', '/pricing', '/faq', '/contact', '/legal/terms', '/legal/privacy'] as $path) {
        $this->get(root($path))->assertOk();
    }
    $this->get(root('/legal/anything'))->assertNotFound();
});

it('stores and forwards contact messages, and quietly drops bot submissions', function () {
    Mail::fake();
    config(['wurk.contact_email' => 'hello@wurk.co.za']);

    $this->post(root('/contact'), ['name' => 'Sipho', 'contact' => 'sipho@x.co.za', 'message' => 'Can you move our salon from another system?'])->assertSessionHas('status');
    $this->post(root('/contact'), ['name' => 'Bot', 'contact' => 'b@x.co', 'message' => 'Buy cheap things now please', 'website' => 'http://spam'])->assertSessionHas('status');
    $this->post(root('/contact'), ['name' => 'Sipho', 'contact' => 'x', 'message' => 'short'])->assertSessionHasErrors('message');

    expect(DB::table('contact_messages')->count())->toBe(1);
    Mail::assertSent(ContactMessageMail::class, 1);
});

it('server-renders only the marketing host', function () {
    $this->get(root('/'));
    expect(config('inertia.ssr.enabled'))->toBeTrue();

    config(['inertia.ssr.enabled' => false]);
    $this->get('http://'.config('wurk.domains.app').'/sign-in');
    expect(config('inertia.ssr.enabled'))->toBeFalse();
});
