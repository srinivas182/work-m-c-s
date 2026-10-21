<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithShards;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Credits\Events\LowCreditBalance;
use Wurk\Credits\Models\CreditBalance;
use Wurk\Credits\Models\CreditLedgerEntry;
use Wurk\Credits\Models\CreditRate;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Actions\Subscriptions;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->context = app(TenantContext::class);
    $this->wallet = app(CreditWallet::class);
});

function inGlow($test, callable $fn): mixed
{
    return $test->context->run($test->glow, $fn);
}

it('charges at the business’s country rate and refuses when the balance is too low', function () {
    inGlow($this, function () {
        $this->wallet->grant('purchase', 5000, 'buy:1', 'Purchase');
        expect($this->wallet->charge('sms', 'msg:1', 'SMS'))->toBe(3000)
            ->and($this->wallet->balance()['balance_milli'])->toBe(2000)
            ->and(fn () => $this->wallet->charge('sms', 'msg:2', 'SMS'))->toThrow(InsufficientCredits::class)
            ->and($this->wallet->balance()['balance_milli'])->toBe(2000);
    });
});

it('never charges or refunds twice for the same key', function () {
    inGlow($this, function () {
        $this->wallet->grant('purchase', 10000, 'buy:1', 'Purchase');
        $this->wallet->grant('purchase', 10000, 'buy:1', 'Purchase (retried)');
        $this->wallet->charge('whatsapp', 'msg:1', 'WhatsApp');
        $this->wallet->charge('whatsapp', 'msg:1', 'WhatsApp (retried)');
        $this->wallet->refund('msg:1', 'Failed');
        $this->wallet->refund('msg:1', 'Failed (retried)');
        $this->wallet->refund('msg:unknown', 'Nothing to refund');

        expect($this->wallet->balance()['balance_milli'])->toBe(10000)
            ->and(CreditLedgerEntry::query()->count())->toBe(3);
    });
});

it('spends the monthly allowance first and expires what is left at the next allowance', function () {
    inGlow($this, function () {
        $this->wallet->grant('purchase', 10000, 'buy:1', 'Purchase');
        $this->wallet->grant('allowance', 6000, 'allowance:2026-11', 'November');
        $this->wallet->charge('sms', 'msg:1', 'SMS'); // 3 credits, from the allowance

        expect($this->wallet->balance())->toMatchArray(['balance_milli' => 13000, 'allowance_milli' => 3000]);

        $this->wallet->grant('allowance', 6000, 'allowance:2026-12', 'December');
        expect($this->wallet->balance())->toMatchArray(['balance_milli' => 16000, 'allowance_milli' => 6000])
            ->and(CreditLedgerEntry::query()->where('type', 'expiry')->value('amount_milli'))->toBe(-3000);
    });
});

it('always keeps the cached balance equal to the ledger (property test)', function () {
    mt_srand(20261119);
    inGlow($this, function () {
        $charged = [];
        for ($i = 0; $i < 300; $i++) {
            $op = mt_rand(0, 9);
            try {
                match (true) {
                    $op === 0 => $this->wallet->grant('allowance', mt_rand(1, 50) * 1000, 'allowance:'.$i, 'A'),
                    $op === 1 => $this->wallet->grant('purchase', mt_rand(1, 20) * 1000, 'buy:'.$i, 'P'),
                    $op === 2 => $this->wallet->grant('adjustment', mt_rand(-5, 5) * 1000 ?: 1000, 'adj:'.$i, 'Adjust'),
                    $op <= 7 => $charged[] = [$this->wallet->charge(['sms', 'whatsapp', 'email', 'ai'][mt_rand(0, 3)], 'msg:'.$i, 'M'), 'msg:'.$i][1],
                    default => $charged !== [] ? $this->wallet->refund($charged[array_rand($charged)], 'R') : null,
                };
            } catch (InsufficientCredits) {
                // Refused writes must leave everything untouched; checked below.
            }

            $balance = CreditBalance::query()->find($this->glow->id);
            $cached = (int) ($balance?->balance_milli ?? 0);
            $allowance = (int) ($balance?->allowance_milli ?? 0);
            $ledger = (int) CreditLedgerEntry::query()->sum('amount_milli');
            expect($cached)->toBe($ledger)->toBeGreaterThanOrEqual(0)
                ->and($allowance)->toBeLessThanOrEqual($cached)->toBeGreaterThanOrEqual(0);
        }
    });
});

it('applies new rates only from their start date', function () {
    CreditRate::query()->create(['country' => 'ZA', 'channel' => 'sms', 'credits_milli' => 4000, 'effective_from' => now()->addWeek()]);
    inGlow($this, fn () => expect($this->wallet->rateFor('sms'))->toBe(3000));

    $this->travel(8)->days();
    inGlow($this, fn () => expect($this->wallet->rateFor('sms'))->toBe(4000));
});

it('raises the low-balance alert once, and again only after a top-up', function () {
    Event::fake([LowCreditBalance::class]);
    inGlow($this, function () {
        $this->wallet->grant('purchase', 30000, 'buy:1', 'P'); // threshold is 20 credits
        $this->wallet->charge('whatsapp', 'm1', 'W');
        $this->wallet->charge('whatsapp', 'm2', 'W'); // 20 → below? no: 30-10 = 20, not below
        $this->wallet->charge('whatsapp', 'm3', 'W'); // 15: below
        $this->wallet->charge('whatsapp', 'm4', 'W'); // 10: already alerted
        $this->wallet->grant('purchase', 30000, 'buy:2', 'P'); // back above: re-arm
        $this->wallet->charge('ai', 'm5', 'A');
        $this->wallet->grant('adjustment', -20000, 'adj:1', 'Correction');
        $this->wallet->charge('ai', 'm6', 'A');
    });

    Event::assertDispatchedTimes(LowCreditBalance::class, 2);
});

it('gives a new business its first allowance, then one per month on its anniversary', function () {
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 0, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => [],
    ], null);

    $this->travelTo('2026-01-31 09:00');
    app(Subscriptions::class)->assign($this->glow->id, $package);
    inGlow($this, fn () => expect($this->wallet->balance()['balance_milli'])->toBe(100000));

    $this->artisan('credits:grant-allowances', ['--date' => '2026-01-31'])->assertSuccessful(); // same month: no second grant
    $this->artisan('credits:grant-allowances', ['--date' => '2026-02-27'])->assertSuccessful(); // not yet
    inGlow($this, fn () => expect(CreditLedgerEntry::query()->where('type', 'allowance')->count())->toBe(1));

    $this->artisan('credits:grant-allowances', ['--date' => '2026-02-28'])->assertSuccessful(); // 31st → last day of February
    inGlow($this, fn () => expect(CreditLedgerEntry::query()->where('type', 'allowance')->count())->toBe(2)
        ->and(CreditLedgerEntry::query()->where('type', 'expiry')->count())->toBe(1));
});

it('charges business messages, refunds failed ones, and refuses without credits — platform messages are free', function () {
    Http::fake(['rest.smsportal.com/*' => Http::sequence()->push(['eventId' => 1], 200)->push(['errorReport' => ['faults' => [['errorMessage' => 'Bad number']]]], 400)->whenEmpty(Http::response(['eventId' => 2], 200))]);
    app(ProviderSettings::class)->save('sms', 'smsportal', ['client_id' => 'c', 'api_secret' => 's'], [], true, null);
    $messenger = app(Messenger::class);

    inGlow($this, function () use ($messenger) {
        expect($messenger->sms('+27821234567', 'Hi', 'reminder')->ok)->toBeFalse(); // no credits: never reaches the provider
        Http::assertNothingSent();

        $this->wallet->grant('purchase', 10000, 'buy:1', 'P');
        expect($messenger->sms('+27821234567', 'Hi', 'reminder')->ok)->toBeTrue()
            ->and($messenger->sms('+27821234567', 'Hi', 'reminder')->ok)->toBeFalse()
            ->and($this->wallet->balance()['balance_milli'])->toBe(7000)
            ->and(CreditLedgerEntry::query()->where('type', 'refund')->count())->toBe(1);
    });

    expect($messenger->sms('+27821234567', 'Your code', 'sign_in_code')->ok)->toBeTrue();
    inGlow($this, fn () => expect($this->wallet->balance()['balance_milli'])->toBe(7000));
});
