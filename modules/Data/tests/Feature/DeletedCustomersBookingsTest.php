<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Crm\Contracts\Audiences;
use Wurk\Crm\Models\Contact;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'marketing'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($this->owner, $this->glow);
    [$this->thandi, $this->conn] = ($this->in)(fn () => [(string) app(Team::class)->add($this->owner->id, 'Thandi', 'owner')->id, $this->ctx->connectionName()]);
    $this->bin = $this->app('/glow-salon/settings/recently-deleted');
});

it('hides a deleted customer everywhere — never in a campaign — puts them back, and erases their details after 30 days', function () {
    $id = ($this->in)(function () {
        $c = Contact::query()->create(['name' => 'Lerato Mokoena', 'phone' => '+27830000001', 'email' => 'lerato@example.com', 'marketing_consent_at' => now(), 'notes' => 'Prefers mornings']);
        DB::connection($this->conn)->table('contact_links')->insert(['tenant_id' => $this->glow->id, 'contact_id' => $c->id, 'original_contact_id' => $c->id, 'source_type' => 'form',
            'source_id' => 'f1', 'summary' => 'Asked about braids', 'occurred_at' => now()]);

        return $c->id;
    });
    $audience = fn () => ($this->in)(fn () => count(app(Audiences::class)->audience('all')));
    expect($audience())->toBe(1);

    $this->actingAs($this->owner)->delete($this->app("/glow-salon/contacts/{$id}"))->assertRedirect()
        ->assertSessionHas('status', 'Lerato Mokoena deleted. You can restore them from Recently deleted for 30 days — after that their details are erased.');
    expect($audience())->toBe(0);                                                     // never messaged while deleted
    $this->actingAs($this->owner)->get($this->app("/glow-salon/contacts/{$id}"))->assertNotFound();
    $this->actingAs($this->owner)->get($this->bin)->assertInertia(fn (Assert $p) => $p->where('groups.0.type', 'customers')->where('groups.0.items.0.name', 'Lerato Mokoena'));

    $this->actingAs($this->owner)->post("{$this->bin}/customers/{$id}")->assertSessionHas('status', 'Lerato Mokoena is back in your customers, with their history.');
    expect($audience())->toBe(1);

    $this->actingAs($this->owner)->delete($this->app("/glow-salon/contacts/{$id}"));
    $this->travel(31)->days();
    $this->artisan('data:purge-deleted')->expectsOutput('Removed 1 for good.');
    $c = ($this->in)(fn () => (array) DB::connection($this->conn)->table('contacts')->where('id', $id)->first());
    expect([$c['name'], $c['phone'], $c['email'], $c['notes'], $c['marketing_consent_at']])->toBe(['Deleted customer', null, null, null, null])
        ->and($c['erased_at'])->not->toBeNull()
        ->and(($this->in)(fn () => DB::connection($this->conn)->table('contact_links')->value('summary')))->toBe('Removed at the customer’s request');
    $this->artisan('data:purge-deleted')->expectsOutput('Removed 0 for good.'); // once only
});

it('puts a cancelled booking back while its time is free — and says plainly when it can’t', function () {
    [$booking, $service] = ($this->in)(function () {
        $s = BookingService::query()->create(['name' => 'Cut', 'duration_minutes' => 30]);
        DB::connection($this->conn)->table('booking_service_member')->insert(['service_id' => $s->id, 'member_id' => $this->thandi, 'tenant_id' => $this->glow->id]);
        foreach (range(0, 6) as $d) {
            BookingHours::query()->create(['member_id' => $this->thandi, 'weekday' => $d, 'starts' => '08:00', 'ends' => '18:00']);
        }
        $at = now($this->ctx->current()->timezone())->addDays(2)->setTime(10, 0);
        $b = Booking::query()->create(['service_id' => $s->id, 'member_id' => $this->thandi, 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes(30), 'status' => 'confirmed', 'customer_name' => 'Lerato']);
        app(Booker::class)->cancel($b, null);

        return [$b->id, $s];
    });
    $this->actingAs($this->owner)->get($this->bin)->assertInertia(fn (Assert $p) => $p->where('groups.0.type', 'bookings')->where('groups.0.items.0.name', fn ($n) => str_starts_with((string) $n, 'Cut — Lerato, ')));

    // Someone else takes the time meanwhile: can't come back.
    $other = ($this->in)(function () use ($booking) {
        $b = Booking::query()->findOrFail($booking);
        $o = Booking::query()->create(['service_id' => $b->service_id, 'member_id' => $this->thandi, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'status' => 'confirmed', 'customer_name' => 'Sipho']);
        DB::connection($this->conn)->table('booking_slots')->insert(['member_id' => $this->thandi, 'slot_at' => $b->starts_at, 'booking_id' => $o->id, 'tenant_id' => $this->glow->id]);

        return $o;
    });
    $this->actingAs($this->owner)->post("{$this->bin}/bookings/{$booking}")->assertSessionHas('warning', 'That time has been booked since — book it again at another time.');
    expect(($this->in)(fn () => Booking::query()->findOrFail($booking)->status))->toBe('cancelled');

    ($this->in)(fn () => app(Booker::class)->cancel($other, null));
    $this->actingAs($this->owner)->post("{$this->bin}/bookings/{$booking}")->assertSessionHas('status', 'Lerato’s booking is back on, and they’ve been told.');
    expect(($this->in)(fn () => [Booking::query()->findOrFail($booking)->status, DB::connection($this->conn)->table('booking_slots')->where('booking_id', $booking)->count()]))
        ->toBe(['confirmed', ($this->in)(fn () => count(Availability::slots(
            CarbonImmutable::instance(Booking::query()->findOrFail($booking)->starts_at), CarbonImmutable::instance(Booking::query()->findOrFail($booking)->starts_at)->addMinutes(30))))]); // its time claimed again
});

it('won’t put back a booking with a deposit, one that has passed, or one cancelled over 30 days ago', function () {
    $make = fn (array $over) => ($this->in)(function () use ($over) {
        $s = BookingService::query()->firstOrCreate(['name' => 'Cut'], ['duration_minutes' => 30]);
        $at = now($this->ctx->current()->timezone())->addDays(3)->setTime(9, 0);

        return Booking::query()->create([...['service_id' => $s->id, 'member_id' => $this->thandi, 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes(30), 'status' => 'cancelled',
            'customer_name' => 'X', 'cancelled_at' => now()], ...$over])->id;
    });
    $deposit = $make(['deposit_cents' => 10000]);
    $this->actingAs($this->owner)->post("{$this->bin}/bookings/{$deposit}")->assertSessionHas('warning', 'A booking with a deposit can’t be put back — book it again so the deposit is taken.');
    $past = $make(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(30)]);
    $this->actingAs($this->owner)->post("{$this->bin}/bookings/{$past}")->assertSessionHas('warning', 'That booking’s time has passed.');
    $old = $make(['cancelled_at' => now()->subDays(31)]);
    $this->actingAs($this->owner)->post("{$this->bin}/bookings/{$old}")->assertSessionHas('warning', 'That booking isn’t in Recently deleted any more.');
});
