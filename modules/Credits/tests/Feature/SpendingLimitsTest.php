<?php

declare(strict_types=1);

use Tests\Support\InteractsWithShards;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Credits\Contracts\SpendingLimitReached;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    ($this->in)(fn () => app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase'));
});

it('stops charges at the business’s monthly limit, and a refund gives the headroom back', function () {
    ($this->in)(function () {
        $wallet = app(CreditWallet::class);
        $wallet->setLimits(9000, [], null); // 9 credits a month
        $wallet->charge('sms', 's1', 'SMS');  // 3
        $wallet->charge('sms', 's2', 'SMS');  // 6
        $wallet->charge('sms', 's3', 'SMS');  // 9
        expect($wallet->spendable())->toBe(0)
            ->and(fn () => $wallet->charge('sms', 's4', 'SMS'))->toThrow(SpendingLimitReached::class, 'monthly spending limit');

        $wallet->refund('s3', 'Not delivered');
        expect($wallet->spentThisMonth())->toBe(6000)
            ->and($wallet->usageThisMonth()['sms'])->toBe(['count' => 2, 'milli' => 6000]) // net of the refund
            ->and($wallet->charge('sms', 's5', 'SMS'))->toBe(3000);
    });
});

it('stops a staff member at their own limit while others carry on', function () {
    ($this->in)(function () {
        $wallet = app(CreditWallet::class);
        $wallet->setLimits(null, ['STAFF1' => 3000], null);
        $wallet->charge('sms', 'a1', 'SMS', 'STAFF1');
        expect(fn () => $wallet->charge('sms', 'a2', 'SMS', 'STAFF1'))->toThrow(SpendingLimitReached::class, 'Ask the business owner')
            ->and($wallet->charge('sms', 'b1', 'SMS', 'STAFF2'))->toBe(3000)
            ->and($wallet->charge('sms', 'o1', 'SMS'))->toBe(3000)
            ->and($wallet->spendable('STAFF1'))->toBe(0)
            ->and($wallet->spendable('STAFF2'))->toBeNull();
    });
});

it('is handled anywhere a short balance is (a limit is a kind of insufficient credits)', function () {
    expect(is_subclass_of(SpendingLimitReached::class, InsufficientCredits::class))->toBeTrue();
});
