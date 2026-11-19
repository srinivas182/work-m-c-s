<?php

declare(strict_types=1);

use Tests\Support\InteractsWithShards;
use Wurk\Platform\Contracts\BusinessCurrency;
use Wurk\Platform\Money\Currency;
use Wurk\Platform\Money\Money;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 25.1b: a business's money is written in its country's currency, the same way on the server and in the
 * browser. Amounts are hundredths for every currency (a CFA franc is 100), so only how they're written changes.
 * WURK's own money (plans, credits, commission) stays in rand.
 */

uses(InteractsWithShards::class);

it('writes each country’s currency its own way, and French readers their way', function () {
    $kes = new Currency('KES', 'KSh', 2, '.', ',', false);
    $xof = new Currency('XOF', 'CFA', 0, ',', ' ', true);
    expect(Money::write(138000, $kes, 'en'))->toBe('KSh1,380.00')
        ->and(Money::write(138000, $kes, 'fr'))->toBe('1 380,00 KSh')
        ->and(Money::write(500000, $xof, 'fr'))->toBe('5 000 CFA')
        ->and(Money::write(-500050, $xof, 'en'))->toBe('-5 001 CFA')
        ->and(Money::zar(138000, 'en'))->toBe('R1 380.00')
        ->and(Money::format(138000, 'en'))->toBe('R1 380.00'); // outside a business: rand
});

it('uses the business’s pack inside a business, shares it with the browser, and drops .00 on short prices', function () {
    $this->createShards(1);
    $nairobi = $this->createTenant('nairobi-cuts');
    $nairobi->forceFill(['country' => 'KE'])->save();
    $abidjan = $this->createTenant('abidjan-coiffure');
    $abidjan->forceFill(['country' => 'CI'])->save();
    $ctx = app(TenantContext::class);

    expect($ctx->run($nairobi, fn () => [Money::format(250000, 'en'), Money::short(35000, 'en'), Money::short(35050, 'en'), Money::zar(29900, 'en')]))
        ->toBe(['KSh2,500.00', 'KSh350', 'KSh350.50', 'R299.00'])
        ->and($ctx->run($abidjan, fn () => [Money::format(500000, 'fr'), app(BusinessCurrency::class)->current()->toArray()]))
        ->toBe(['5 000 CFA', ['code' => 'XOF', 'symbol' => 'CFA', 'decimals' => 0, 'decimal' => ',', 'thousands' => ' ', 'after' => true]]);
});
