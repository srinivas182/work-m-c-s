<?php

declare(strict_types=1);

namespace Wurk\Platform\Money;

use Wurk\Platform\Contracts\BusinessCurrency;

/**
 * Money as WURK writes it (25.1b), the same rule as the browser's (resources/js/i18n.ts → money()).
 *
 * - {@see format()} for a business's own money (prices, sales, invoices, deposits): its country's currency.
 * - {@see zar()} for WURK's own money (plans, credits, commission, HQ figures): rand.
 *
 * The country decides the symbol, decimals and separators: R1 380.00, KSh1,380.00, 1 380 CFA. A reader of French or
 * Portuguese sees a point-decimal currency in their own style: 1 380,00 R.
 */
final class Money
{
    public static function format(int $cents, ?string $locale = null): string
    {
        return self::write($cents, self::business(), $locale);
    }

    /** Like {@see format()}, without ".00" on a whole amount: "from R350" on a website or a share card. */
    public static function short(int $cents, ?string $locale = null): string
    {
        $c = self::business();

        return self::write($cents, $cents % 100 === 0 ? new Currency($c->code, $c->symbol, 0, $c->decimalSeparator, $c->thousandsSeparator, $c->symbolAfter) : $c, $locale);
    }

    public static function zar(int $cents, ?string $locale = null): string
    {
        return self::write($cents, Currency::zar(), $locale);
    }

    public static function business(): Currency
    {
        return app()->bound(BusinessCurrency::class) ? app(BusinessCurrency::class)->current() : Currency::zar();
    }

    public static function write(int $cents, Currency $c, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        [$decimal, $thousands, $after] = [$c->decimalSeparator, $c->thousandsSeparator, $c->symbolAfter];
        if (in_array($locale, ['fr', 'pt'], true) && $decimal === '.') {
            [$decimal, $thousands, $after] = [',', ' ', true];
        }
        $value = abs($cents) / 100; // hundredths, always (see Currency)
        $n = number_format($value, $c->decimals, $decimal, $thousands);
        $sign = $cents < 0 ? '-' : '';

        return $after ? $sign.$n.' '.$c->symbol : $sign.$c->symbol.$n;
    }
}
