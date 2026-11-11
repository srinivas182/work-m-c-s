<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Deposits;
use Wurk\Campaigns\Support\CampaignSender;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\AudienceSegments;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class, FakesYoco::class);

const COUPON_STYLIST = '01J00000000000000000000001';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    $this->fakeYoco();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->connectYoco($this->glow);
    $this->service = ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000, 'deposit_type' => 'fixed', 'deposit_value' => 10000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => COUPON_STYLIST, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => COUPON_STYLIST, 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);
        $db = DB::connection(app(TenantContext::class)->connectionName());
        foreach ([['DEP20', 'percent', 2000, 'bookings'], ['FREE', 'percent', 10000, 'both'], ['SHOPONLY', 'amount', 1000, 'shop']] as [$code, $kind, $value, $for]) {
            $db->table('coupons')->insert(['id' => (string) Str::ulid(), 'tenant_id' => app(TenantContext::class)->id(), 'code' => $code, 'kind' => $kind, 'value' => $value,
                'applies_to' => $for, 'min_spend_cents' => 0, 'once_per_customer' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $service;
    });
    $this->book = fn (string $at, ?string $coupon) => ($this->in)(function () use ($at, $coupon) {
        $booking = app(Booker::class)->book($this->service, CarbonImmutable::parse('2027-05-03 '.$at, 'Africa/Johannesburg'), ['name' => 'Sipho', 'phone' => '082 000 0001', 'email' => 'sipho@example.com'], COUPON_STYLIST, 'website', status: 'pending_payment');

        return [$booking, app(Deposits::class)->start($booking, 10000, 'sipho@example.com', 'https://glow.example/book/return', null, $coupon)];
    });
    $this->uses = fn () => ($this->in)(fn () => DB::connection($this->ctx->connectionName())->table('coupon_redemptions')->whereNull('released_at')->count());
});

it('takes a coupon off a booking deposit — and gives the use back if the booking doesn’t stand', function () {
    [$booking, $url] = ($this->book)('09:00', 'dep20');
    expect($url)->toStartWith('https://c.yoco.com/')->and(($this->in)(fn () => $booking->refresh()->deposit_cents))->toBe(8000)->and(($this->uses)())->toBe(1); // R100 − 20%
    expect(fn () => ($this->book)('10:00', 'SHOPONLY'))->toThrow(DomainException::class, 'That code isn’t valid.');               // not for bookings
    [$free, $back] = ($this->book)('11:00', 'FREE');
    expect($back)->toContain('book/return?reference=')->and(($this->in)(fn () => [$free->refresh()->status, $free->deposit_cents]))->toBe(['confirmed', 0]); // nothing to pay
    ($this->in)(fn () => app(Booker::class)->cancel($booking->refresh(), null));
    expect(($this->uses)())->toBe(1);                                                                                               // DEP20's use came back
});

it('offers top referrers as an audience while the referral programme is on, and shows a campaign’s coupon sales', function () {
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'marketing'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    [$thandi, $sipho] = ($this->in)(function () {
        $book = app(ContactBook::class);
        $thandi = $book->remember('Thandi', null, 'thandi@example.com', 'order', 'o1', null, 'Order', 'checkout');
        $sipho = $book->remember('Sipho', null, 'sipho@example.com', 'order', 'o2', null, 'Order', 'checkout');
        $db = DB::connection($this->ctx->connectionName());
        $t = $this->ctx->id();
        $member = fn (string $contact, ?string $by) => tap((string) Str::ulid(), fn ($id) => $db->table('referral_members')->insert(['id' => $id, 'tenant_id' => $t, 'contact_id' => $contact,
            'code' => Str::upper(Str::random(8)), 'referrer_id' => $by, 'created_at' => now(), 'updated_at' => now()]));
        $top = $member($thandi, null);
        $member($sipho, $top);
        $member((string) Str::ulid(), $top);                                                      // Thandi referred two

        return [$thandi, $sipho];
    });
    expect(($this->in)(fn () => app(AudienceSegments::class)->available()))->toBe([]);         // no referral programme on this package
    $p2 = app(PackageCatalog::class)->create([
        'key' => 'pro', 'name' => 'Pro', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 2,
        'monthly_price_cents' => 99900, 'yearly_price_cents' => 999000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'marketing', 'referrals'],
    ], null);
    app(Catalog::class)->changePackage($this->glow->id, $p2->id, 'monthly');
    app(Entitlements::class)->flush();
    ($this->in)(function () use ($thandi) {
        expect(app(AudienceSegments::class)->available())->toBe(['referrers' => 'Your top referrers'])
            ->and(array_column(app(CampaignSender::class)->recipients('email', 'referrers', null), 'id'))->toBe([$thandi]);
    });

    $owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($owner, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));
    ($this->in)(function () {
        $db = DB::connection($this->ctx->connectionName());
        $t = $this->ctx->id();
        $campaign = (string) Str::ulid();
        $db->table('campaigns')->insert(['id' => $campaign, 'tenant_id' => $t, 'name' => 'May offer', 'channel' => 'email', 'segment' => 'all', 'body' => 'Hi', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('coupons')->where('code', 'DEP20')->update(['campaign_id' => $campaign]);
        $coupon = (string) $db->table('coupons')->where('code', 'DEP20')->value('id');
        $db->table('coupon_redemptions')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'coupon_id' => $coupon, 'source_type' => 'booking', 'source_id' => 'b-1', 'discount_cents' => 2000, 'created_at' => now()]);
        event(new PurchaseMade('booking', 'b-1', 8000, 8000, null, now()));                     // what that booking paid
    });
    $this->actingAs($owner)->get($this->app('/glow-salon/campaigns'))->assertInertia(fn ($p) => $p->where('campaigns.0', fn ($c) => $c['redeemed'] === 1 && $c['sales_cents'] === 8000));
});
