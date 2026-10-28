<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->biz = $this->createTenant('glow-salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->biz);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->biz, $fn);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Thandi', 'owner'));
    $this->package = function (bool $multi, int $max) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.$max, 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 10,
            'multi_branch' => $multi, 'max_branches' => $max, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['bookings'],
        ], null);
        app(Catalog::class)->subscribe($this->biz->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    $this->base = $this->app('/glow-salon/settings/branches');
    $this->add = fn (string $name) => $this->actingAs($this->owner)->post($this->base, ['name' => $name, 'address' => "1 {$name} St"]);
});

it('keeps a one-branch package to its main branch', function () {
    ($this->package)(false, 1);
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Team::Branches')->where('limit', 1)->has('branches', 1)->where('branches.0.main', true));
    ($this->add)('Sandton')->assertSessionHasErrors(['name' => 'Your package has one branch. Upgrade to add more.']);
});

it('adds branches up to the package limit; closing one frees a place; the main branch stays open', function () {
    ($this->package)(true, 3);
    ($this->add)('Sandton')->assertSessionHas('status', 'Branch added.');
    ($this->add)('Rosebank')->assertSessionHas('status', 'Branch added.');
    ($this->add)('Soweto')->assertSessionHasErrors(['name' => 'Your package allows 3 branches. Close one, or upgrade to add more.']);

    [$main, $sandton] = ($this->in)(fn () => [app(BranchDirectory::class)->main(), collect(app(BranchDirectory::class)->open())->firstWhere('name', 'Sandton')['id']]);
    $this->actingAs($this->owner)->put("{$this->base}/{$main}/closed", ['closed' => true])->assertSessionHasErrors(['closed' => 'The main branch can’t be closed.']);
    $this->actingAs($this->owner)->put("{$this->base}/{$sandton}/closed", ['closed' => true])->assertSessionHas('status', 'Branch closed. Its history is kept.');
    ($this->add)('Soweto')->assertSessionHas('status', 'Branch added.');
    $this->actingAs($this->owner)->put("{$this->base}/{$sandton}/closed", ['closed' => false])->assertSessionHasErrors(['closed' => 'Your package allows 3 open branches.']);

    ($this->in)(function () {
        $open = app(BranchDirectory::class)->open();
        expect($open[0]['main'])->toBeTrue()->and(array_column($open, 'name'))->toContain('Rosebank', 'Soweto')->not->toContain('Sandton')
            ->and(collect($open)->firstWhere('name', 'Rosebank')['address'])->toBe('1 Rosebank St') // details really saved
            ->and(app(BranchDirectory::class)->several())->toBeTrue()->and(count(app(BranchDirectory::class)->all()))->toBe(4);
    });
});
