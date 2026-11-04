<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Attribution;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1); // both businesses on the same shard
    $this->ctx = app(TenantContext::class);
    $this->in = fn ($t, callable $fn) => $this->ctx->run($t, $fn);
    $this->glow = $this->createTenant('glow-salon');
    $this->kasi = $this->createTenant('kasi-threads');
    foreach ([$this->glow, $this->kasi] as $t) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.$t->id, 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 3,
            'referral_max_commission_bp' => 1500, 'managed_fee_cents' => null, 'modules' => ['website', 'referrals'],
        ], null);
        app(Catalog::class)->subscribe($t->id, $p->id, 'monthly', allowTrial: true);
        ($this->in)($t, fn () => ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 200, 'level_bp' => [500], 'attribution' => 'first', 'link_days' => 30, 'return_days' => 14]));
    }
    app(Entitlements::class)->flush();
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    ($this->in)($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
    $this->customer = fn ($t, string $name, string $phone, ?string $code = null) => ($this->in)($t, function () use ($name, $phone, $code) {
        $id = app(ContactBook::class)->remember($name, $phone, null, 'test', (string) Str::ulid(), null, 'Test');
        app(Attribution::class)->attach($id, $code);

        return ReferralMember::query()->where('contact_id', $id)->sole();
    });
    $this->url = $this->app('/glow-salon/referrals');
});

it('lists the business’s customers with their codes, links, referrer and how many they referred — and their QR codes', function () {
    $thandi = ($this->customer)($this->glow, 'Thandi', '082 000 0001');
    ($this->customer)($this->glow, 'Sipho', '082 000 0002', $thandi->code);
    ($this->customer)($this->glow, 'Lerato', '082 000 0003', $thandi->code);
    $kasi = ($this->customer)($this->kasi, 'Dineo', '082 000 0004');

    $this->actingAs($this->owner)->get($this->url)->assertInertia(function (Assert $p) use ($thandi) {
        $p->component('Referrals::Members')->where('enabled', true)->where('pages.total', 3);
        $rows = collect($p->toArray()['props']['members'])->keyBy('name');
        expect($rows->keys()->sort()->values()->all())->toBe(['Lerato', 'Sipho', 'Thandi']) // never Kasi Threads' Dineo
            ->and($rows['Thandi']['referred'])->toBe(2)->and($rows['Sipho']['referred_by'])->toBe('Thandi')
            ->and($rows['Thandi']['link'])->toBe('http://glow-salon.'.config('wurk.domains.root').'/r/'.$thandi->code);
    });
    $this->actingAs($this->owner)->get($this->url.'?code='.substr($thandi->code, 0, 4))->assertInertia(fn (Assert $p) => $p->where('members.0.name', 'Thandi'));

    $qr = $this->actingAs($this->owner)->get("{$this->url}/{$thandi->id}/qr.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect((string) $qr->getContent())->toStartWith('<?xml')->toContain('<svg');
    $this->actingAs($this->owner)->get("{$this->url}/{$kasi->id}/qr.svg")->assertNotFound(); // another business's customer
});
