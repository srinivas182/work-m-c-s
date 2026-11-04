<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1); // both businesses on the same shard
    $this->ctx = app(TenantContext::class);
    $this->subscribe = function ($tenant, array $modules) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.$tenant->id, 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 3,
            'referral_max_commission_bp' => 1500, 'managed_fee_cents' => null, 'modules' => $modules,
        ], null);
        app(Catalog::class)->subscribe($tenant->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    $this->owner = function ($tenant) {
        $o = $this->person();
        $this->member($o, $tenant);
        $this->ctx->run($tenant, fn () => app(Team::class)->add($o->id, 'Owner', 'owner'));

        return $o;
    };
    $this->glow = $this->createTenant('glow-salon');
    $this->kasi = $this->createTenant('kasi-threads');
    $this->rules = ['enabled' => true, 'self_bp' => 200, 'level_bp' => [500, 200], 'attribution' => 'first', 'link_days' => 30, 'return_days' => 14];
});

it('is only there for packages with the referral programme', function () {
    ($this->subscribe)($this->glow, ['website']);
    $o = ($this->owner)($this->glow);
    expect($this->actingAs($o)->get($this->app('/glow-salon/settings/referrals'))->status())->toBeIn([403, 404]);
});

it('starts off, saves rules within the package’s guardrails (logged), and refuses what’s outside them in plain words', function () {
    ($this->subscribe)($this->glow, ['website', 'referrals']);
    $o = ($this->owner)($this->glow);
    $url = $this->app('/glow-salon/settings/referrals');
    $this->actingAs($o)->get($url)->assertInertia(fn (Assert $p) => $p->component('Referrals::Settings')
        ->where('rules', ['enabled' => false, 'self_bp' => 0, 'level_bp' => [], 'attribution' => 'first', 'link_days' => 30, 'return_days' => 14])
        ->where('limits', ['max_levels' => 3, 'max_bp' => 1500]));

    $this->actingAs($o)->put($url, $this->rules)->assertSessionHas('status', 'Referral programme saved — it’s on.');
    expect($this->ctx->run($this->glow, fn () => app(ReferralRules::class)->rules()))->toBe($this->rules);

    $this->actingAs($o)->put($url, [...$this->rules, 'level_bp' => [100, 100, 100, 100]])->assertSessionHasErrors(['level_bp' => 'Your package allows up to 3 levels.']);
    $this->actingAs($o)->put($url, [...$this->rules, 'self_bp' => 900])->assertSessionHasErrors(['total' => 'Together these come to 16%; your package allows up to 15% in total.']);
    $this->actingAs($o)->put($url, [...$this->rules, 'self_bp' => 0, 'level_bp' => []])->assertSessionHasErrors(['total' => 'Set at least one percentage before switching the programme on.']);
    $this->actingAs($o)->put($url, [...$this->rules, 'link_days' => 0])->assertSessionHasErrors(['link_days' => 'A link should count for at least a day.']);
    expect($this->ctx->run($this->glow, fn () => app(AuditEvent::class)::query()->where('action', 'referrals.rules')->count()))->toBe(1);
});

it('keeps each business’s rules its own on a shared shard', function () {
    foreach ([$this->glow, $this->kasi] as $t) {
        ($this->subscribe)($t, ['website', 'referrals']);
    }
    $this->actingAs(($this->owner)($this->glow))->put($this->app('/glow-salon/settings/referrals'), $this->rules)->assertSessionHasNoErrors();
    expect($this->ctx->run($this->kasi, fn () => app(ReferralRules::class)->rules()['enabled']))->toBeFalse()
        ->and($this->ctx->run($this->kasi, fn () => ReferralProgramme::query()->count()))->toBe(0);
});
