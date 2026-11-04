<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Attribution;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1); // both businesses on the same shard
    $this->glow = $this->createTenant('glow-salon');
    $this->kasi = $this->createTenant('kasi-threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn ($t, callable $fn) => $this->ctx->run($t, $fn);
    $this->on = fn ($t, string $attribution = 'first') => ($this->in)($t, fn () => ReferralProgramme::query()->updateOrCreate([], ['enabled' => true, 'self_bp' => 200, 'level_bp' => [500], 'attribution' => $attribution, 'link_days' => 30, 'return_days' => 14]));
    // A customer remembered (as from a booking, order, form or chat), with an optional referral code in the request.
    $this->customer = fn ($t, string $name, string $phone, ?string $code = null) => ($this->in)($t, function () use ($name, $phone, $code) {
        $id = app(ContactBook::class)->remember($name, $phone, null, 'test', (string) Str::ulid(), null, 'Test');
        app(Attribution::class)->attach($id, $code);

        return ReferralMember::query()->where('contact_id', $id)->sole();
    });
    $this->site = 'http://glow-salon.'.config('wurk.domains.root');
});

it('sends link visitors to the site — remembering the code only while the programme runs, and only to pages on this site', function () {
    $thandi = null;
    $this->get("{$this->site}/r/ABCDEFG")->assertRedirect('/')->assertCookieMissing('wurk_ref'); // off, unknown code
    ($this->on)($this->glow);
    $thandi = ($this->customer)($this->glow, 'Thandi', '082 000 0001');
    $r = $this->get("{$this->site}/r/{$thandi->code}?to=/prices")->assertRedirect('/prices');
    $cookie = collect($r->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'wurk_ref');
    expect($cookie->getValue())->toBe($thandi->code)->and($cookie->isHttpOnly())->toBeTrue()
        ->and(round(($cookie->getExpiresTime() - time()) / 86400))->toBe(30.0);
    $this->get("{$this->site}/r/{$thandi->code}?to=//evil.example")->assertRedirect('/');
});

it('credits the referrer when a referred visitor becomes a customer — through a real quote request', function () {
    ($this->on)($this->glow);
    $thandi = ($this->customer)($this->glow, 'Thandi', '082 000 0001');
    $this->withUnencryptedCookie('wurk_ref', $thandi->code)
        ->post('http://'.config('wurk.domains.app').'/forms/glow-salon/quote', ['name' => 'Sipho', 'phone' => '082 555 0101', 'message' => 'Braids please', 'consent' => '1'])->assertOk();
    ($this->in)($this->glow, function () use ($thandi) {
        $sipho = ReferralMember::query()->where('id', '!=', $thandi->id)->sole();
        expect($sipho->referrer_id)->toBe($thandi->id);
    });
});

it('keeps the first referrer, or the last — and refuses self-referral, loops and other businesses’ codes', function () {
    ($this->on)($this->glow);
    $a = ($this->customer)($this->glow, 'Ayanda', '082 000 0001');
    $b = ($this->customer)($this->glow, 'Bongi', '082 000 0002');
    $c = ($this->customer)($this->glow, 'Chris', '082 000 0003', $a->code);
    expect($c->referrer_id)->toBe($a->id);
    expect(($this->customer)($this->glow, 'Chris', '082 000 0003', $b->code)->referrer_id)->toBe($a->id); // first: kept
    ($this->on)($this->glow, 'last');
    expect(($this->customer)($this->glow, 'Chris', '082 000 0003', $b->code)->referrer_id)->toBe($b->id); // last: replaced

    expect(($this->customer)($this->glow, 'Ayanda', '082 000 0001', $a->code)->referrer_id)->toBeNull();   // self-referral
    expect(($this->customer)($this->glow, 'Bongi', '082 000 0002', $c->code)->referrer_id)->toBeNull();    // C was referred by B: a loop

    ($this->on)($this->kasi);
    expect(($this->customer)($this->kasi, 'Dineo', '082 000 0004', $a->code)->referrer_id)->toBeNull();    // Glow's code at Kasi Threads
});
