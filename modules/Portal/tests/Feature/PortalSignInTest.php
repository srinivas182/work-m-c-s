<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Models\Contact;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Attribution;
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

it('shows the signed-in customer their wallet, link and QR, who they referred (first names) and their own history', function () {
    // A package with referrals (without one, the package's cap is 0% and rewards are — rightly — nothing).
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'referrals'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    ($this->in)($this->glow, function () {
        ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 1000, 'level_bp' => [], 'attribution' => 'first', 'link_days' => 30]);
        $book = app(ContactBook::class);
        $sipho = $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'order', '01JD00000000000000000ORD01', null, 'Order #1001 · R500');
        $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'form', '01JD00000000000000000FRM01', null, 'Website enquiry');       // internal: not shown
        $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'sale', '01JD00000000000000000SAL01', null, 'In-store sale: R120.00');
        event(new PurchaseMade('order', '01JD00000000000000000ORD01', 50000, 50000, null, now()->subDay())); // already final: R50 available
        $lerato = $book->remember('Lerato Dube', '082 000 0009', null, 'test', 'seed', null, 'Seed');
        app(Attribution::class)->attach($lerato, app(Attribution::class)->member($sipho)->code);
    });
    $site = ($this->site)('glow-salon');
    $code = ($this->code)($site, 'sipho@example.com');
    $this->post("{$site}/verify", ['code' => $code]);
    $this->post("{$site}/consent", ['agree' => '1']);

    $this->get($site)->assertInertia(fn (Assert $p) => $p->component('Portal::Home')
        ->where('rewards.wallet_cents', 5000)->where('rewards.pending_cents', 0)->where('rewards.expiry_months', 12)
        ->where('rewards.referred.0.name', 'Lerato') // first name only
        ->where('history', fn ($h) => collect($h)->pluck('type')->sort()->values()->all() === ['order', 'sale']));
    $qr = $this->get("{$site}/referral-qr.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect((string) $qr->getContent())->toContain('<svg');
    $this->post("{$site}/sign-out");
    $this->get("{$site}/referral-qr.svg")->assertNotFound();

    // A business without a programme: no rewards section. (Wait out the resend limit: codes to the same address are spaced.)
    $this->travel(2)->minutes();
    $kasi = ($this->site)('kasi-threads');
    $this->post("{$kasi}/verify", ['code' => ($this->code)($kasi, 'sipho@example.com'), 'name' => 'Sipho Ndlovu']); // Kasi Threads hasn't met him: it asks
    $this->post("{$kasi}/consent", ['agree' => '1']);
    $this->get($kasi)->assertInertia(fn (Assert $p) => $p->component('Portal::Home')->where('rewards', null));
});

it('gives a signed-in customer a short-lived wallet pass that only identifies them, at this business, unaltered', function () {
    $site = ($this->site)('glow-salon');
    $this->getJson("{$site}/wallet-pass")->assertOk()->assertJson(['signed_in' => false]); // not signed in: no pass
    $code = ($this->code)($site, 'sipho@example.com');
    $this->post("{$site}/verify", ['code' => $code, 'name' => 'Sipho Ndlovu']);
    $this->getJson("{$site}/wallet-pass")->assertJson(['signed_in' => false]);            // not yet consented
    $this->post("{$site}/consent", ['agree' => '1']);
    $r = $this->getJson("{$site}/wallet-pass?total=25000")->assertOk()->assertJson(['signed_in' => true, 'available_cents' => 0, 'usable_cents' => 0])->assertHeader('Cache-Control', 'no-store, private');
    $pass = (string) $r->json('pass');
    $sipho = ($this->in)($this->glow, fn () => app(ContactBook::class)->find(null, 'sipho@example.com')['id']);

    ($this->in)($this->glow, function () use ($pass, $sipho) {
        $credit = app(StoreCredit::class);
        expect($credit->contactFromPass($pass))->toBe($sipho)
            ->and($credit->contactFromPass('x'.$pass))->toBeNull()                       // altered
            ->and($credit->contactFromPass(substr($pass, 0, -1).(str_ends_with($pass, '0') ? '1' : '0')))->toBeNull(); // signature altered (always a different character)
    });
    expect(($this->in)($this->kasi, fn () => app(StoreCredit::class)->contactFromPass($pass)))->toBeNull(); // another business
    $this->travel(16)->minutes();
    expect(($this->in)($this->glow, fn () => app(StoreCredit::class)->contactFromPass($pass)))->toBeNull(); // expired
});

it('shows the signed-in customer a QR proving who they are — and nobody else', function () {
    $site = ($this->site)('glow-salon');
    $this->get("{$site}/id-qr.svg")->assertNotFound();
    $this->post("{$site}/verify", ['code' => ($this->code)($site, 'sipho@example.com'), 'name' => 'Sipho Ndlovu']);
    $this->get("{$site}/id-qr.svg")->assertNotFound(); // not until they've agreed
    $this->post("{$site}/consent", ['agree' => '1']);
    $qr = $this->get("{$site}/id-qr.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Cache-Control', 'no-store, private');
    expect((string) $qr->getContent())->toContain('<svg');
});

it('gives the signed-in customer a full copy of their information, and records a deletion request once — audit without personal details', function () {
    ($this->in)($this->glow, function () {
        $book = app(ContactBook::class);
        $id = $book->remember('Sipho Ndlovu', '082 555 0101', 'sipho@example.com', 'order', '01JD00000000000000000ORD01', null, 'Order #1001 · R500');
        $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'form', '01JD00000000000000000FRM01', null, 'Website enquiry');
        Contact::query()->whereKey($id)->update(['notes' => 'Prefers Saturdays']);
    });
    $site = ($this->site)('glow-salon');
    $this->get("{$site}/my-information.json")->assertNotFound(); // signed out
    $this->post("{$site}/verify", ['code' => ($this->code)($site, 'sipho@example.com')]);
    $this->post("{$site}/consent", ['agree' => '1']);

    $copy = $this->get("{$site}/my-information.json")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="my-information-glow-salon.json"');
    $data = json_decode((string) $copy->getContent(), true);
    expect($data['business'])->toBe('Glow Salon')
        ->and($data['you'])->toMatchArray(['name' => 'Sipho Ndlovu', 'email' => 'sipho@example.com'])
        ->and(collect($data['history'])->pluck('type')->sort()->values()->all())->toContain('form', 'order') // their own enquiries too
        ->and($data['notes_the_business_keeps_about_you']['notes'])->toBe('Prefers Saturdays')
        ->and($data['account']['consented_at'])->not->toBeNull();

    $this->post("{$site}/delete-request", [])->assertSessionHasErrors(['confirm' => 'Please confirm you understand what deletion means.']);
    $this->post("{$site}/delete-request", ['confirm' => '1'])->assertRedirect('/account');
    $this->post("{$site}/delete-request", ['confirm' => '1']);
    $this->get($site)->assertInertia(fn (Assert $p) => $p->where('deletion.status', 'open'));
    ($this->in)($this->glow, function () {
        $db = DB::connection($this->ctx->connectionName());
        expect($db->table('data_requests')->where('tenant_id', $this->ctx->id())->where('status', 'open')->count())->toBe(1);
        // Every stored field of every portal entry (sign-in and consent too) — the model's array form hides most of them.
        $events = AuditEvent::query()->where('action', 'like', 'portal.%')->get()->map(fn ($e) => $e->getAttributes());
        expect($events->pluck('action')->sort()->values()->all())->toBe(['portal.consented', 'portal.data_copy', 'portal.deletion_requested', 'portal.signed_in'])
            ->and($events->pluck('actor_name')->unique()->values()->all())->toBe(['Customer'])
            ->and(json_encode($events->all()))->not->toContain('Sipho')->not->toContain('sipho@example.com')->not->toContain('555'); // decision H
    });
});
