<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    $this->createShards(1); // both businesses on the same shard
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->kasi = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn ($t, callable $fn) => $this->ctx->run($t, $fn);
    $this->site = fn (string $h) => 'http://'.$h.'.'.config('wurk.domains.root').'/account';
    $this->code = function (string $site, string $contact): string {
        $this->post("{$site}/code", ['contact' => $contact])->assertSessionHas('status');

        // Codes are kept under the normalised address (+27… for SA numbers), as the real sender gets them.
        $key = collect(array_keys(FakeOtpChannel::$codes))->first(fn ($k) => $k === $contact || str_ends_with((string) $k, substr(preg_replace('/\D/', '', $contact), 1)));

        return FakeOtpChannel::$codes[$key];
    };
});

it('signs in a new customer with a code, asks their name, takes POPIA consent once, then opens their portal', function () {
    $site = ($this->site)('glow-salon');
    $this->get($site)->assertInertia(fn (Assert $p) => $p->component('Portal::SignIn')->where('business', 'Glow Salon'));
    $code = ($this->code)($site, 'sipho@example.com');
    $this->post("{$site}/verify", ['code' => $code])->assertSessionHasErrors(['name' => 'Tell us your name to finish signing in.']); // not known yet
    $this->post("{$site}/verify", ['code' => $code, 'name' => 'Sipho Ndlovu'])->assertRedirect();

    $this->get($site)->assertInertia(fn (Assert $p) => $p->component('Portal::Consent'));
    $this->post("{$site}/consent", [])->assertSessionHasErrors(['agree' => 'Please agree to continue.']);
    $this->post("{$site}/consent", ['agree' => '1'])->assertRedirect('/account');
    $this->get($site)->assertInertia(fn (Assert $p) => $p->component('Portal::Home')->where('name', 'Sipho Ndlovu')->where('navigation', []));
    expect(($this->in)($this->glow, fn () => app(ContactBook::class)->find(null, 'sipho@example.com')['name']))->toBe('Sipho Ndlovu');
});

it('recognises a known customer by phone (no name needed, no duplicate) and refuses a wrong code', function () {
    $known = ($this->in)($this->glow, fn () => app(ContactBook::class)->remember('Thandi Mokoena', '082 000 0001', null, 'test', 'seed', null, 'Seed'));
    $site = ($this->site)('glow-salon');
    $code = ($this->code)($site, '0820000001');
    $this->post("{$site}/verify", ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors(['code' => 'That code isn’t right, or it has expired. Ask for a new one.']);
    $this->post("{$site}/verify", ['code' => $code])->assertRedirect();
    ($this->in)($this->glow, function () use ($known) {
        expect(DB::connection($this->ctx->connectionName())->table('portal_customers')->where('tenant_id', $this->ctx->id())->value('contact_id'))->toBe($known)
            ->and(DB::connection($this->ctx->connectionName())->table('contacts')->where('tenant_id', $this->ctx->id())->count())->toBe(1);
    });
});

it('counts a portal session only for the business it was started at, and signs out', function () {
    $glow = ($this->site)('glow-salon');
    $code = ($this->code)($glow, 'sipho@example.com');
    $this->post("{$glow}/verify", ['code' => $code, 'name' => 'Sipho Ndlovu']);
    $this->post("{$glow}/consent", ['agree' => '1']);
    $this->get(($this->site)('kasi-threads'))->assertInertia(fn (Assert $p) => $p->component('Portal::SignIn')->where('business', 'Kasi Threads')); // same session, other business
    $this->post("{$glow}/sign-out")->assertRedirect('/account');
    $this->get($glow)->assertInertia(fn (Assert $p) => $p->component('Portal::SignIn'));
});
