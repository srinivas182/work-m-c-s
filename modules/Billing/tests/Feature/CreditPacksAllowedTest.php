<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Models\PackageVersion;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function packageTerms(array $extra = []): array
{
    return [...[
        'key' => 'trial', 'name' => 'Trial', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 0, 'yearly_price_cents' => 0, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 1,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 10, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], ...$extra];
}

it('keeps credit packs allowed unless a package switches them off', function () {
    $existing = app(PackageCatalog::class)->create(packageTerms(['key' => 'old']), null);
    expect(PackageVersion::query()->where('package_id', $existing->id)->value('credit_packs_allowed'))->toBeTrue(); // absent = allowed
});

it('refuses credit packs on packages that switch them off, and says so on the billing page', function () {
    $this->createShards(1);
    $glow = $this->createTenant('glow-salon');
    $package = app(PackageCatalog::class)->create(packageTerms(['credit_packs_allowed' => false]), null);
    app(Catalog::class)->subscribe($glow->id, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $owner = $this->person();
    $this->member($owner, $glow);
    app(TenantContext::class)->run($glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));

    $this->actingAs($owner)->get($this->app('/glow-salon/settings/billing'))
        ->assertInertia(fn (Assert $p) => $p->where('creditPacksAllowed', false)->where('packs', []));
    $this->actingAs($owner)->post($this->app('/glow-salon/settings/billing/credits'), ['pack_id' => 'anything'])
        ->assertSessionHasErrors(['pack_id' => 'Your package doesn’t include buying credit packs. Upgrade for more monthly credits.']);
});
