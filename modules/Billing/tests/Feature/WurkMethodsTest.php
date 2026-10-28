<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithShards;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Support\Payments;
use Wurk\Payments\Contracts\PlatformPaymentReceived;
use Wurk\Payments\Models\PlatformPaymentAccount;

uses(InteractsWithShards::class, FakesYoco::class);

beforeEach(function () {
    $this->fakeYoco();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon']);
    PlatformPaymentAccount::query()->create(['provider' => 'yoco', 'mode' => 'test', 'active' => true, 'test_credentials' => ['secret_key' => 'sk_test_wurk', 'webhook_secret' => 'whsec_eA==']]);
    $this->checkout = fn () => app(Payments::class)->checkout($this->glow->id, 'credits', 10000, ['credits' => 100, 'pack' => 'Starter'], 'https://app.wurk.test/glow-salon/settings/billing/return', 'yoco');
});

it('pays WURK with one of WURK’s methods and settles through the normal path', function () {
    Event::fake([PaymentSucceeded::class]);
    $result = ($this->checkout)();
    $payment = $result['payment'];
    expect($result['url'])->toBe('https://c.yoco.com/checkout/ch_1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/checkouts') && $r['amount'] === $payment->total_cents
        && $r['successUrl'] === 'https://app.wurk.test/glow-salon/settings/billing/return?reference='.rawurlencode($payment->reference));

    event(new PlatformPaymentReceived($payment->reference)); // WURK's notification, verified
    expect($payment->refresh()->status)->toBe('succeeded');
    Event::assertDispatched(PaymentSucceeded::class);
});

it('refuses a short payment, as for cards', function () {
    $payment = ($this->checkout)()['payment'];
    $this->yoco['amount'] = 100;
    app(Payments::class)->confirm($payment->reference);
    expect($payment->refresh()->only(['status', 'failure']))->toBe(['status' => 'failed', 'failure' => 'Amount or currency did not match']);
});
