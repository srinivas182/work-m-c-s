<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Billing\Mail\DunningNoticeMail;
use Wurk\Billing\Mail\PrepaidNoticeMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditPack;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\PrepaidPlans;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 22.5: pay as you go. A prepaid package's price comes out of WURK credits each day; warnings at 7, 3 and 1 day(s)
 * left; a grace period when they run out, then the usual dunning; a top-up (from R20) restores the business at once.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2028-11-10 00:10', 'Africa/Johannesburg')); // November: 30 days
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->package = app(PackageCatalog::class)->create([
        'key' => 'kasi', 'name' => 'Kasi Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 9000, 'yearly_price_cents' => 90000, 'trial_days' => 0, 'storage_mb' => 512, 'staff_limit' => 1,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'prepaid' => true, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $this->package->id, 'monthly', allowTrial: false);
    app(Catalog::class)->markPaid($this->glow->id, now(), now()); // starts paid up to today
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon']);
    CreditPack::query()->create(['name' => 'Small', 'credits' => 100, 'price_cents' => 10000, 'active' => true]); // R1 a credit, excl. VAT
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    $this->give = fn (int $credits, string $key) => ($this->in)(fn () => app(CreditWallet::class)->grant('purchase', $credits * 1000, $key, 'Test'));
    $this->balance = fn () => ($this->in)(fn () => app(CreditWallet::class)->balance()['balance_milli']);
    $this->run = fn () => $this->artisan('billing:charge-prepaid')->assertSuccessful();
});

it('takes the day’s price from credits — R90 a month is 3 credits a day in November — once a day, never invoiced', function () {
    expect(app(Catalog::class)->subscriptionOf($this->glow->id)['prepaid'])->toBeTrue()
        ->and(app(PrepaidPlans::class)->dailyMilli(9000, CarbonImmutable::now()))->toBe(3000);
    ($this->give)(30, 'p1');
    ($this->run)();
    ($this->run)(); // again the same day: nothing more
    expect(($this->balance)())->toBe(27000)
        ->and(app(Catalog::class)->dueForRenewal(now()->addMonth()))->toBe([]) // never picked up for an invoice
        ->and(app(PrepaidPlans::class)->status($this->glow->id))->toBe(['daily_milli' => 3000, 'days_left' => 9, 'ran_out_on' => null, 'grace_days' => 3]);
    Mail::assertNothingSent();
});

it('warns at 3 and 1 day(s) left, gives the grace period, then the usual dunning; a top-up restores it at once', function () {
    ($this->give)(13, 'p1');
    ($this->run)(); // 10 left: 3 days
    Mail::assertSent(PrepaidNoticeMail::class, fn ($m) => $m->kind === 'low' && $m->days === 3 && $m->hasTo('owner@glow.co.za'));
    $this->travel(1)->days();
    ($this->run)(); // 7 left: 2 days, no new warning
    $this->travel(1)->days();
    ($this->run)(); // 4 left: 1 day
    Mail::assertSent(PrepaidNoticeMail::class, fn ($m) => $m->kind === 'low' && $m->days === 1);
    $this->travel(1)->days();
    ($this->run)(); // 1 left: today’s 3 can’t be paid
    $this->travel(1)->days();
    ($this->run)(); // still out: the grace period counts from the first day; the last credit is never part-taken
    Mail::assertSent(PrepaidNoticeMail::class, fn ($m) => $m->kind === 'empty' && $m->days === 3);
    expect(app(Catalog::class)->subscriptionOf($this->glow->id)['status'])->toBe('active'); // in grace

    $this->travel(3)->days();
    ($this->run)();
    expect(app(Catalog::class)->subscriptionOf($this->glow->id)['status'])->toBe('past_due');
    Mail::assertSent(DunningNoticeMail::class);

    // Topping up pays today and restores the business at once.
    $payment = Payment::query()->create(['tenant_id' => $this->glow->id, 'purpose' => 'credits', 'reference' => 'top-1', 'gateway' => 'paystack', 'status' => 'succeeded',
        'amount_excl_cents' => 2000, 'vat_cents' => 300, 'total_cents' => 2300, 'vat_bp' => 1500, 'currency' => 'ZAR', 'details' => ['credits' => 20]]);
    ($this->give)(20, 'purchase:'.$payment->id);
    event(new PaymentSucceeded($payment->id));
    expect(app(Catalog::class)->subscriptionOf($this->glow->id)['status'])->toBe('active')
        ->and(BillingAccount::query()->find($this->glow->id)->prepaid_ran_out_on)->toBeNull()
        ->and(($this->balance)())->toBe(18000);
});

it('warns on WhatsApp first, falling back to SMS when WhatsApp can’t deliver', function () {
    BillingAccount::query()->whereKey($this->glow->id)->update(['billing_phone' => '+27821234567']);
    $messenger = new class implements Messenger
    {
        /** @var list<array<int, mixed>> */
        public array $sent = [];

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            $this->sent[] = ['sms', $purpose];

            return SendResult::sent('s1');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            $this->sent[] = ['whatsapp', $template, $parameters];

            return $template === PrepaidPlans::TEMPLATE_LOW ? SendResult::sent('w1') : SendResult::failed('template not approved');
        }

        public function channelActive(string $channel): bool
        {
            return true;
        }
    };
    $this->app->instance(Messenger::class, $messenger);
    ($this->give)(10, 'p1');
    ($this->run)(); // 7 left: 2 days
    $this->travel(1)->days();
    ($this->run)(); // 4 left: 1 day
    $this->travel(1)->days();
    ($this->run)(); // 1 left
    $this->travel(1)->days();
    ($this->run)(); // out
    expect($messenger->sent[0][0])->toBe('whatsapp')->and($messenger->sent[0][1])->toBe('wurk_credits_low')->and($messenger->sent[0][2][0])->toBe('Glow Salon')
        ->and(collect($messenger->sent)->contains(fn ($s) => $s[0] === 'sms' && $s[1] === 'prepaid_empty'))->toBeTrue(); // the "out" template isn't approved yet: SMS instead
});

it('lets owners top up any amount from R20 and shows how many days their credits last', function () {
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/t']])]);
    $owner = $this->person();
    $this->member($owner, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($owner->id, 'Zodwa', 'owner'));
    ($this->give)(30, 'p1');
    ($this->run)();
    $this->actingAs($owner)->get($this->app('/glow-salon/settings/billing'))->assertInertia(fn (Assert $p) => $p
        ->where('prepaid.days_left', 9)->where('minTopUp', 20));

    $this->actingAs($owner)->post($this->app('/glow-salon/settings/billing/credits'), ['amount' => '10'])->assertSessionHasErrors(['amount' => 'Top up at least R20.']);
    $this->actingAs($owner)->post($this->app('/glow-salon/settings/billing/credits'), ['amount' => '23'])->assertRedirect('https://checkout.paystack.com/t');
    $payment = Payment::query()->where('purpose', 'credits')->sole();
    expect($payment->details)->toEqual(['pack' => 'Top-up', 'credits' => 20])->and($payment->total_cents)->toBe(2300);
    Http::assertSent(fn (Request $r) => $r['amount'] === 2300);
});

it('shows on Home how long the credits last', function () {
    $owner = $this->person();
    $this->member($owner, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($owner->id, 'Zodwa', 'owner'));
    ($this->give)(30, 'p1');
    ($this->run)();
    $this->actingAs($owner)->get($this->app('/glow-salon'))->assertInertia(fn (Assert $p) => $p
        ->where('tiles', fn ($tiles) => collect($tiles)->contains(fn ($t) => $t['key'] === 'billing.prepaid' && $t['value'] === 'About 9 days left')));
});
