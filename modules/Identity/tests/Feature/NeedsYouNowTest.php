<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Order;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;

/*
 * Sprint 26.3: "Needs you now" on Home — one list across WURK, newest first, each item with its action right there;
 * "All done for now." when nothing is waiting. Each person sees only what their role covers.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-05 12:00', 'Africa/Johannesburg'));
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
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'sam@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->member($this->staff, $this->glow);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner');
        app(Team::class)->add($this->staff->id, 'Sam', 'staff');
    });
    $this->home = $this->app('/glow-salon');
});

it('says "All done for now." when nothing is waiting', function () {
    $this->actingAs($this->owner)->get($this->home)->assertInertia(fn (Assert $p) => $p->component('Identity::Workspace')->where('needs', []));
});

it('lists what’s waiting across WURK, newest first, with actions — and only what each person’s role covers', function () {
    ($this->in)(function () {
        $order = fn (int $n, string $status, array $more = []) => Order::query()->forceCreate(['number' => $n, 'status' => $status, 'fulfilment' => 'collection', 'customer_name' => "Customer {$n}",
            'customer_phone' => '+27825550202', 'subtotal_cents' => 30000, 'delivery_cents' => 0, 'total_cents' => 30000, ...$more]);
        $order(1041, 'awaiting_payment', ['payment_method' => 'offline', 'created_at' => now()->subDays(3)]);  // owed
        $order(1042, 'paid', ['payment_method' => 'card', 'paid_at' => now()->subHour()]);                   // to pack
        $order(1043, 'ready', ['payment_method' => 'card', 'paid_at' => now()->subHours(2)]);                // to collect
        ReceptionistConversation::query()->create(['channel' => 'whatsapp', 'visitor' => str_repeat('c', 64), 'status' => 'waiting', 'name' => 'Ayanda', 'last_message_at' => now()->subMinutes(40)]);
        ReceptionistConversation::query()->create(['channel' => 'website', 'visitor' => str_repeat('d', 64), 'status' => 'waiting', 'name' => 'Just now', 'last_message_at' => now()->subMinutes(5)]);
        app(CreditWallet::class)->grant('purchase', 5000, 'buy:1', 'Purchase');
        app(CreditWallet::class)->charge('sms', 'sms:1', 'An SMS', null); // in use, and below the low mark
    });

    $this->actingAs($this->owner)->get($this->home)->assertInertia(fn (Assert $p) => $p
        ->where('needs.0.title', 'Credits running out')->where('needs.0.actions.0.label', 'Top up')
        ->where('needs.1.title', 'Ayanda')->where('needs.1.actions.0.label', 'Answer') // waiting 40 minutes; the 5-minute chat isn't listed
        ->where('needs.2.title', '#1042 · Customer 1042')->where('needs.2.kind', 'Order to pack')->where('needs.2.actions.0.label', 'Packed')
        ->where('needs.3.title', '#1043 · Customer 1043')->where('needs.3.actions.0.label', 'Collected')
        ->where('needs.4.kind', 'Owed to you')->where('needs.4.title', 'Customer 1041 · R300.00')->where('needs.4.actions.0.label', 'Paid: cash')
        ->has('needs', 5));

    // The action right there: the pay-later order is paid in cash, and drops off the list.
    $settle = ($this->in)(fn () => '/glow-salon/shop/orders/'.Order::query()->where('number', 1041)->value('id').'/advance');
    $this->actingAs($this->owner)->post($this->app($settle), ['how' => 'cash', 'to' => 'paid'])->assertSessionHas('status', 'Marked as paid: cash.');
    $this->actingAs($this->owner)->get($this->home)->assertInertia(fn (Assert $p) => $p->where('needs', fn ($needs) => collect($needs)->pluck('kind')->doesntContain('Owed to you')
        && collect($needs)->firstWhere('title', '#1041 · Customer 1041')['kind'] === 'Order to pack')); // now it's to pack

    $this->actingAs($this->staff)->get($this->home)->assertInertia(fn (Assert $p) => $p->where('needs', [])); // staff: none of it is theirs
});
