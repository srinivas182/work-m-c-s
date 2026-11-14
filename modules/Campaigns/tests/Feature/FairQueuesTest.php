<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Campaigns\Mail\CampaignMail;
use Wurk\Campaigns\Support\CampaignSender;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

/*
| Sprint 18.3a — fair queuing (PRD §15): one business's big campaign must not delay another business's booking
| confirmation. Real queue (database driver), real worker, three queues in priority order.
*/

beforeEach(function () {
    Mail::fake();
    config(['queue.default' => 'database']);
    [$shard] = $this->createShards(1);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'marketing', 'bookings'],
    ], null);
    $this->ctx = app(TenantContext::class);
    $this->glow = $this->createTenant('glow-salon', $shard, 'Glow Salon');
    $this->bake = $this->createTenant('bravo-bakery', $shard, 'Bravo Bakery');
    foreach ([$this->glow, $this->bake] as $t) {
        app(Catalog::class)->subscribe($t->id, $p->id, 'monthly', allowTrial: true);
    }
    app(Entitlements::class)->flush();
    $this->work = fn () => $this->artisan('queue:work', ['--once' => true, '--queue' => 'critical,default,bulk', '--sleep' => 0])->run();
    $this->waiting = fn (string $queue) => DB::table('jobs')->where('queue', $queue)->count();
});

it('sends a booking confirmation before another business’s 1,000-customer campaign — the campaign never has more than one job waiting', function () {
    // Glow Salon: 1,000 customers who agreed to marketing, and an email campaign to all of them.
    $campaign = $this->ctx->run($this->glow, function () {
        $db = DB::connection($this->ctx->connectionName());
        foreach (array_chunk(range(1, 1000), 250) as $chunk) {
            $db->table('contacts')->insert(array_map(fn ($n) => ['id' => strtolower((string) Str::ulid()), 'tenant_id' => $this->glow->id, 'name' => "Customer {$n}",
                'email' => "c{$n}@example.com", 'marketing_consent_at' => now(), 'created_at' => now(), 'updated_at' => now()], $chunk));
        }
        $id = strtolower((string) Str::ulid());
        $db->table('campaigns')->insert(['id' => $id, 'tenant_id' => $this->glow->id, 'name' => 'Winter sale', 'channel' => 'email', 'segment' => 'all',
            'subject' => 'Hi {first_name}', 'body' => 'Winter sale!', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        expect(app(CampaignSender::class)->send($id))->toBe(1000);              // started, not sent, in the request

        return $id;
    });
    expect(($this->waiting)('bulk'))->toBe(1);
    ($this->work)();                                                            // lists the 1,000 customers
    expect(($this->waiting)('bulk'))->toBe(1);

    // Meanwhile a customer books at Bravo Bakery.
    $booking = $this->ctx->run($this->bake, function () {
        $service = BookingService::query()->create(['name' => 'Cake tasting', 'duration_minutes' => 30]);
        $at = now()->addDay()->setTime(10, 0);
        $b = Booking::query()->create(['service_id' => $service->id, 'member_id' => (string) Str::ulid(), 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes(30),
            'status' => 'confirmed', 'customer_name' => 'Ayanda', 'customer_email' => 'ayanda@example.com']);
        app(BookingMessages::class)->send($b, 'confirmed');

        return $b->id;
    });
    expect(($this->waiting)('critical'))->toBe(1);

    ($this->work)();                                                            // the very next job: the booking confirmation
    Mail::assertSent(BookingMessageMail::class, fn (BookingMessageMail $m) => $m->hasTo('ayanda@example.com'));
    expect(Mail::sent(CampaignMail::class))->toHaveCount(0)
        ->and($this->ctx->run($this->bake, fn () => DB::connection($this->ctx->connectionName())->table('booking_messages')->where('booking_id', $booking)->value('status')))->toBe('sent');

    // The campaign goes out in batches, one waiting job at a time.
    $rounds = 0;
    while (DB::table('jobs')->count() > 0 && $rounds++ < 20) {
        expect(($this->waiting)('bulk'))->toBeLessThanOrEqual(1);
        ($this->work)();
    }
    expect($rounds)->toBe(5)                                                    // 1,000 customers ÷ 200 a batch
        ->and(Mail::sent(CampaignMail::class))->toHaveCount(1000)
        ->and($this->ctx->run($this->glow, fn () => (array) DB::connection($this->ctx->connectionName())->table('campaigns')->where('id', $campaign)->first(['status', 'recipients'])))
        ->toBe(['status' => 'sent', 'recipients' => 1000]);
});

it('skips customers who withdrew consent after the campaign started', function () {
    [$campaign, $lerato] = $this->ctx->run($this->glow, function () {
        $db = DB::connection($this->ctx->connectionName());
        $ids = [];
        foreach (['Thandi', 'Lerato'] as $name) {
            $ids[$name] = strtolower((string) Str::ulid());
            $db->table('contacts')->insert(['id' => $ids[$name], 'tenant_id' => $this->glow->id, 'name' => $name, 'email' => strtolower($name).'@example.com',
                'marketing_consent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = strtolower((string) Str::ulid());
        $db->table('campaigns')->insert(['id' => $id, 'tenant_id' => $this->glow->id, 'name' => 'News', 'channel' => 'email', 'segment' => 'all',
            'subject' => 'News', 'body' => 'Hello', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        app(CampaignSender::class)->send($id);

        return [$id, $ids['Lerato']];
    });
    ($this->work)();                                                            // listed: both
    $this->ctx->run($this->glow, fn () => DB::connection($this->ctx->connectionName())->table('contacts')->where('id', $lerato)->update(['marketing_consent_at' => null]));
    ($this->work)();                                                            // sent: Thandi only
    Mail::assertSent(CampaignMail::class, 1);
    expect($this->ctx->run($this->glow, fn () => DB::connection($this->ctx->connectionName())->table('campaign_messages')->where('contact_id', $lerato)->value('error')))
        ->toBe('No longer agreed to marketing, or no email address');
});
