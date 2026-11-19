<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Order;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;

/*
 * Sprint 26.2: Today, where the WURK app opens. A stylist sees their own bookings for the rest of the day with
 * Start, Done, No-show and Paid in person; the owner also sees chats waiting and orders to pack. Nobody can press
 * the buttons on someone else's booking unless they manage bookings.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-05 08:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'shop'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->stylist = $this->person(['phone' => '+27831000002', 'email' => 'lindiwe@glow.co.za']);
    $this->other = $this->person(['phone' => '+27831000003', 'email' => 'naledi@glow.co.za']);
    foreach ([$this->owner, $this->stylist, $this->other] as $p) {
        $this->member($p, $this->glow);
    }
    [$this->mine, $this->theirs] = ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner');
        $lindiwe = (string) app(Team::class)->add($this->stylist->id, 'Lindiwe', 'staff')->id;
        $naledi = (string) app(Team::class)->add($this->other->id, 'Naledi', 'staff')->id;
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000]);
        foreach ([$lindiwe, $naledi] as $m) {
            DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $m, 'tenant_id' => $this->glow->id]);
        }
        $at = fn (string $t) => CarbonImmutable::parse("2027-05-05 {$t}", 'Africa/Johannesburg');
        $mine = app(Booker::class)->book($service, $at('10:00'), ['name' => 'Thandi Mokoena'], $lindiwe, asOwner: true);
        app(Booker::class)->book($service, $at('12:00'), ['name' => 'Lerato Molefe'], $lindiwe, asOwner: true);
        app(Booker::class)->book($service, CarbonImmutable::parse('2027-05-06 10:00', 'Africa/Johannesburg'), ['name' => 'Tomorrow'], $lindiwe, asOwner: true);
        $theirs = app(Booker::class)->book($service, $at('11:00'), ['name' => 'Sipho Dlamini'], $naledi, asOwner: true);
        ReceptionistConversation::query()->create(['channel' => 'whatsapp', 'visitor' => str_repeat('b', 64), 'status' => 'waiting', 'name' => 'Ayanda', 'last_message_at' => now()]);
        Order::query()->forceCreate(['number' => 1042, 'status' => 'paid', 'payment_method' => 'card', 'fulfilment' => 'collection', 'customer_name' => 'Sipho Dlamini',
            'customer_phone' => '+27825550202', 'subtotal_cents' => 30000, 'delivery_cents' => 0, 'total_cents' => 30000, 'paid_at' => now()]);

        return [$mine, $theirs];
    });
    $this->today = $this->app('/glow-salon/today');
});

it('opens on the stylist’s own bookings for today, and lets them start, finish and take payment', function () {
    $this->actingAs($this->stylist)->get($this->today)->assertInertia(fn (Assert $p) => $p->component('Identity::Today')->where('date', 'Wednesday 5 May')
        ->has('sections', 1)->where('sections.0.key', 'bookings')
        ->where('sections.0.items.0.title', 'Thandi Mokoena')->where('sections.0.items.0.time', '10:00')->where('sections.0.items.0.detail', 'Knotless braids · to pay R450.00')
        ->where('sections.0.items.1.title', 'Lerato Molefe')->has('sections.0.items', 2) // not tomorrow's, not Naledi's
        ->where('sections.0.items.0.actions.0.label', 'Start')->where('sections.0.items.0.actions.2.label', 'No-show'));

    $base = $this->app('/glow-salon/bookings/today/'.$this->mine->id);
    $this->actingAs($this->stylist)->post($base.'/start')->assertSessionHas('status', 'Started: Thandi Mokoena.');
    $this->actingAs($this->stylist)->post($base.'/paid', ['method' => 'cash'])->assertSessionHas('status', 'R450.00 recorded: cash.');
    $this->actingAs($this->stylist)->post($base.'/paid', ['method' => 'cash'])->assertSessionHas('warning', 'Nothing is left to pay on this booking.');
    $this->actingAs($this->stylist)->post($base.'/status', ['status' => 'completed'])->assertSessionHas('status', 'Marked as done.');
    ($this->in)(function () {
        expect(Booking::query()->find($this->mine->id)->only(['status']))->toBe(['status' => 'completed'])
            ->and(Booking::query()->find($this->mine->id)->started_at)->not->toBeNull()
            ->and(app(InPersonPayments::class)->takenFor('booking', $this->mine->id))->toBe(45000);
    });
    $this->actingAs($this->stylist)->get($this->today)->assertInertia(fn (Assert $p) => $p->where('sections.0.items.0.badge', 'Done')->where('sections.0.items.0.actions', []));
});

it('keeps other people’s bookings to those who manage bookings, and shows the owner chats and orders too', function () {
    $this->actingAs($this->stylist)->post($this->app('/glow-salon/bookings/today/'.$this->theirs->id.'/status'), ['status' => 'no_show'])->assertNotFound();

    $this->actingAs($this->owner)->get($this->today)->assertInertia(fn (Assert $p) => $p
        ->where('sections.0.key', 'chats')->where('sections.0.items.0.title', 'Ayanda')->where('sections.0.items.0.badge', 'Waiting')
        ->where('sections.1.key', 'orders')->where('sections.1.items.0.title', '#1042 · Sipho Dlamini')->where('sections.1.items.0.actions.0.label', 'Packed'));
    $this->actingAs($this->owner)->post($this->app('/glow-salon/bookings/today/'.$this->theirs->id.'/status'), ['status' => 'no_show'])->assertSessionHas('status', 'Marked as a no-show.');
    $this->actingAs($this->owner)->post($this->app('/glow-salon/shop/orders/'.($this->in)(fn () => Order::query()->value('id')).'/advance'), ['to' => 'packed'])->assertSessionHas('status', 'Marked as packed.');
});

it('opens the installed app on Today', function () {
    $this->actingAs($this->stylist)->get('http://'.config('wurk.domains.app').'/businesses?to=today')->assertRedirect($this->today);
});
