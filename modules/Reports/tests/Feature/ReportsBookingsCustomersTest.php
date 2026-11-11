<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Contracts\PurchaseRefunded;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $start = now()->startOfMonth();
    $this->ctx->run($this->glow, function () use ($start) {
        $db = DB::connection($this->ctx->connectionName());
        $t = $this->ctx->id();
        $service = (string) Str::ulid();
        $db->table('booking_services')->insert(['id' => $service, 'tenant_id' => $t, 'name' => 'Braids', 'duration_minutes' => 60, 'price_cents' => 20000, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['completed', 2], ['completed', 3], ['no_show', 4], ['cancelled', 5], ['completed', -30]] as [$status, $hours]) {
            $at = $start->copy()->addHours($hours);
            $db->table('bookings')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'service_id' => $service, 'member_id' => (string) Str::ulid(),
                'starts_at' => $at, 'ends_at' => $at->copy()->addHour(), 'status' => $status, 'customer_name' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        }
        $link = fn (string $contact, string $source, $at) => $db->table('contact_links')->insert(['contact_id' => $contact, 'original_contact_id' => $contact, 'tenant_id' => $t,
            'source_type' => 'order', 'source_id' => $source, 'summary' => 'Order', 'occurred_at' => $at]);
        $buy = fn (string $source, int $cents) => event(new PurchaseMade('order', $source, $cents, $cents, null, now()));
        [$a, $b, $c] = [(string) Str::ulid(), (string) Str::ulid(), (string) Str::ulid()];
        $link($a, 'a1', now()->subYear());
        $buy('a1', 10000);                    // A: an old customer…
        $link($a, 'a2', $start->copy()->addHours(6));
        $buy('a2', 30000);          // …back this month
        $link($b, 'b1', $start->copy()->addHours(7));
        $buy('b1', 10000);          // B: new this month, twice
        $link($b, 'b2', $start->copy()->addHours(8));
        $buy('b2', 5000);
        event(new PurchaseRefunded('order', 'b2', 5000));                        // (refunded)
        $link($c, 'c1', now()->subMonths(3));
        $buy('c1', 8000);                   // C: not seen for 3 months
    });
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
    $this->travel(1)->days();
});

it('reports bookings — value, no-shows and busiest time — and customers — new, returning, repeat, lifetime value, lapsed', function () {
    $this->actingAs($this->owner)->get($this->app('/glow-salon/reports'))->assertInertia(fn (Assert $p) => $p->component('Reports::Overview')
        ->where('sections.1.key', 'bookings')
        ->where('sections.1.metrics.0', ['label' => 'Bookings', 'value' => 3, 'previous' => 1, 'format' => 'count'])   // cancellations excluded
        ->where('sections.1.metrics.1.value', 60000)->where('sections.1.metrics.2.value', 33.3)
        ->where('sections.2.key', 'customers')
        ->where('sections.2.metrics.0.value', 2)->where('sections.2.metrics.1.value', 1)->where('sections.2.metrics.2.value', 100)
        ->where('sections.2.metrics.3.value', 25000)                                                                    // (R400 + R100) / 2
        ->where('highlights', fn ($h) => collect($h)->contains('33.3% of booked customers didn’t show — a reminder or a deposit could help.')
            && collect($h)->contains('1 new customer, and 1 came back.')
            && collect($h)->contains('1 customer hasn’t been back in 60 days — a win-back message could bring them in.')
            && collect($h)->contains(fn ($x) => str_starts_with((string) $x, 'Your busiest time was '))));
});
