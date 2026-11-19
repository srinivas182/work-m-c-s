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
use Wurk\Bookings\Support\Deposits;
use Wurk\Loyalty\Support\Loyalty;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\OrderPlacer;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 26.5: loyalty cards on WhatsApp. A stamp after each paid visit (cash at the counter too), once per visit;
 * the 8th gives the reward, which the customer is told about, sees on their account and in reply to "my card", and
 * which is used once — at the counter or at checkout, never on top of a coupon — and given back if the order falls away.
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
    foreach ([$this->owner, $this->stylist] as $p) {
        $this->member($p, $this->glow);
    }
    $this->wa = new class implements WhatsAppBusiness
    {
        /** @var list<array{string, string, list<string>}> */
        public array $templates = [];

        /** @var list<array{string, string}> */
        public array $texts = [];

        public function connected(): bool
        {
            return true;
        }

        public function number(): ?string
        {
            return '27110000000';
        }

        public function chatText(): string
        {
            return '';
        }

        public function sendText(string $to, string $text): SendResult
        {
            $this->texts[] = [$to, $text];

            return SendResult::sent('wamid.t');
        }

        public function sendMedia(string $to, string $kind, string $contents, string $mime, string $filename, string $caption = ''): SendResult
        {
            return SendResult::failed('no');
        }

        public function sendInteractive(string $to, array $interactive): SendResult
        {
            return SendResult::failed('no');
        }

        public function sendChoices(string $to, string $text, array $choices): SendResult
        {
            return SendResult::failed('no');
        }

        public function templateReady(string $name): bool
        {
            return $name === WhatsAppBusiness::LOYALTY_PROGRESS;
        }

        public function sendTemplate(string $to, string $name, array $parameters, array $buttonPayloads = []): SendResult
        {
            $this->templates[] = [$to, $name, array_values($parameters)];

            return SendResult::sent('wamid.p');
        }

        public function followUpReady(): bool
        {
            return false;
        }

        public function sendFollowUp(string $to, string $name): SendResult
        {
            return SendResult::failed('no');
        }

        public function followUpText(string $name): string
        {
            return '';
        }

        public function media(string $mediaId): ?array
        {
            return null;
        }
    };
    $this->app->instance(WhatsAppBusiness::class, $this->wa);
    [$this->lindiwe, $this->cut] = ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner');
        $l = (string) app(Team::class)->add($this->stylist->id, 'Lindiwe', 'staff')->id;
        $cut = BookingService::query()->create(['name' => 'Haircut', 'duration_minutes' => 30, 'price_cents' => 20000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')
            ->insert(['service_id' => $cut->id, 'member_id' => $l, 'tenant_id' => $this->glow->id]);

        return [$l, $cut];
    });
    $this->settings = $this->app('/glow-salon/customers/loyalty');
    $this->thandi = ['name' => 'Thandi Mokoena', 'phone' => '+27825550101'];
    $this->visit = fn (string $at) => ($this->in)(fn () => app(Booker::class)->book($this->cut, CarbonImmutable::parse($at, 'Africa/Johannesburg'), $this->thandi, $this->lindiwe, asOwner: true));
});

it('lets the owner set the card up, with plain checks', function () {
    $this->actingAs($this->owner)->put($this->settings, ['on' => true, 'reward' => 'your free cut', 'kind' => 'percent', 'value' => '100', 'stamps' => 1, 'counts' => ['booking']])
        ->assertSessionHasErrors(['stamps' => 'Choose from 2 to 30 stamps.']);
    $this->actingAs($this->owner)->put($this->settings, ['on' => true, 'reward' => 'your free cut', 'kind' => 'percent', 'value' => '100', 'stamps' => 8, 'counts' => []])
        ->assertSessionHasErrors(['counts' => 'Choose what earns a stamp.']);
    $this->actingAs($this->owner)->put($this->settings, ['on' => true, 'reward' => 'your free cut', 'kind' => 'percent', 'value' => '100', 'stamps' => 8, 'counts' => ['booking', 'sale']])
        ->assertSessionHas('status', 'Saved.');
    $this->actingAs($this->owner)->get($this->settings)->assertInertia(fn (Assert $p) => $p->component('Loyalty::Settings')
        ->where('settings.stamps', 8)->where('settings.value', 10000)->where('example', '6 of 8 — 2 more to your free cut.'));
    $this->actingAs($this->stylist)->get($this->settings)->assertNotFound();
});

it('stamps each paid visit once — cash too — and the 8th earns the reward, used once at the counter', function () {
    $this->actingAs($this->owner)->put($this->settings, ['on' => true, 'reward' => 'your free cut', 'kind' => 'percent', 'value' => '100', 'stamps' => 8, 'min_spend' => '150', 'counts' => ['booking']]);
    for ($d = 1; $d <= 7; $d++) {
        $b = ($this->visit)(sprintf('2027-04-%02d 10:00', $d));
        ($this->in)(function () use ($b) {
            app(Deposits::class)->paidInPerson($b, 'cash', 10000, null, 'Lindiwe'); // half: not yet a stamp
            app(Deposits::class)->paidInPerson($b, 'card_machine', 10000, null, 'Lindiwe'); // the rest: one stamp
        });
    }
    expect(($this->in)(fn () => app(Loyalty::class)->forPhone('082 555 0101')))->toMatchArray(['stamps' => 7, 'needed' => 8, 'ready' => 0, 'line' => '7 of 8 — 1 more to your free cut.']);
    expect($this->wa->templates)->toHaveCount(7)->and($this->wa->templates[0])->toBe(['27825550101', WhatsAppBusiness::LOYALTY_PROGRESS, ['Thandi', 'Glow Salon', '1 of 8 — 7 more to your free cut.']]);

    // The 8th, paid in cash from Today.
    $eighth = ($this->visit)('2027-05-05 10:00');
    $this->actingAs($this->stylist)->post($this->app('/glow-salon/bookings/today/'.$eighth->id.'/paid'), ['method' => 'cash'])->assertSessionHas('status', 'R200.00 recorded: cash.');
    expect(($this->in)(fn () => app(Loyalty::class)->forPhone('+27825550101')))->toMatchArray(['stamps' => 0, 'ready' => 1, 'line' => 'Your reward is ready: your free cut. It’s used at your next visit or order.']);

    // The next visit: "Use reward" at the counter, once.
    $ninth = ($this->visit)('2027-05-05 14:00');
    $this->actingAs($this->stylist)->get($this->app('/glow-salon/today'))->assertInertia(fn (Assert $p) => $p
        ->where('sections.0.items.1.actions', fn ($a) => collect($a)->contains('label', 'Use reward: R200.00 off')));
    $reward = $this->app('/glow-salon/bookings/today/'.$ninth->id.'/reward');
    $this->actingAs($this->stylist)->post($reward)->assertSessionHas('status', 'Reward used: R200.00 off.');
    $this->actingAs($this->stylist)->post($reward)->assertStatus(422);
    $this->actingAs($this->stylist)->post($this->app('/glow-salon/bookings/today/'.$ninth->id.'/paid'), ['method' => 'cash'])->assertSessionHas('warning', 'Nothing is left to pay on this booking.');
    expect(($this->in)(fn () => [Booking::query()->find($ninth->id)->reward_cents, app(Loyalty::class)->forPhone('+27825550101')['ready']]))->toBe([20000, 0]);
});

it('answers “my card” on WhatsApp straight away, and shows it on the customer’s account', function () {
    $this->actingAs($this->owner)->put($this->settings, ['on' => true, 'reward' => 'your free cut', 'kind' => 'percent', 'value' => '100', 'stamps' => 8, 'counts' => ['booking']]);
    $b = ($this->visit)('2027-04-20 10:00');
    ($this->in)(fn () => app(Deposits::class)->paidInPerson($b, 'cash', 20000, null, 'Lindiwe'));
    ($this->in)(fn () => event(new WhatsAppReceived($this->glow->id, '27825550101', 'Thandi', 'wamid.in1', 'text', 'My card?', null, null, null, new DateTimeImmutable)));
    expect($this->wa->texts)->toBe([['+27825550101', '1 of 8 — 7 more to your free cut.']]);
});

it('takes the reward off at checkout instead of a smaller coupon, once, and gives it back if the order falls away', function () {
    $this->actingAs($this->owner)->put($this->settings, ['on' => true, 'reward' => 'R50 off', 'kind' => 'amount', 'value' => '50', 'stamps' => 2, 'counts' => ['booking', 'order']]);
    [$mug] = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'delivery_enabled' => false]);
        app(BusinessPayments::class)->setUpEft('FNB, account 62000000000');
        $p = Product::query()->create(['name' => 'Hair oil', 'slug' => 'hair-oil', 'status' => 'published', 'options' => []]);

        return [Variant::query()->create(['product_id' => $p->id, 'price_cents' => 15000, 'stock' => null])];
    });
    foreach (['2027-04-20 10:00', '2027-04-27 10:00'] as $at) {
        $b = ($this->visit)($at);
        ($this->in)(fn () => app(Deposits::class)->paidInPerson($b, 'cash', 20000, null, 'Lindiwe'));
    }
    $order = ($this->in)(fn () => app(OrderPlacer::class)->place([['variant' => $mug->id, 'quantity' => 1]], $this->thandi, 'collection', 'offline'));
    expect($order->only(['discount_cents', 'total_cents', 'coupon_code']))->toBe(['discount_cents' => 5000, 'total_cents' => 10000, 'coupon_code' => null]);
    $again = ($this->in)(fn () => app(OrderPlacer::class)->place([['variant' => $mug->id, 'quantity' => 1]], $this->thandi, 'collection', 'offline'));
    expect($again->discount_cents)->toBe(0); // used once

    ($this->in)(fn () => app(OrderPlacer::class)->release($order, 'cancelled', null));
    expect(($this->in)(fn () => app(Loyalty::class)->forPhone('+27825550101')['ready']))->toBe(1); // given back
});
