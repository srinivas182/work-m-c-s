<?php

declare(strict_types=1);

namespace Wurk\Platform\Money;

/**
 * How a currency is written (25.1b), from the business's country pack. Amounts are always kept in hundredths of the
 * currency (cents), whatever it is: a CFA franc is stored as 100, the same as a rand, so every price, total and
 * parse in WURK works the same way; only how it's written differs (CFA has no decimals, so "5 000 CFA").
 */
final readonly class Currency
{
    public function __construct(
        public string $code = 'ZAR',
        public string $symbol = 'R',
        public int $decimals = 2,
        public string $decimalSeparator = '.',
        public string $thousandsSeparator = ' ',
        public bool $symbolAfter = false,
    ) {}

    /** WURK's own money (plans, credits, agents' commission, HQ figures) is in rand. */
    public static function zar(): self
    {
        return new self;
    }

    /** @return array{code: string, symbol: string, decimals: int, decimal: string, thousands: string, after: bool} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'symbol' => $this->symbol, 'decimals' => $this->decimals, 'decimal' => $this->decimalSeparator,
            'thousands' => $this->thousandsSeparator, 'after' => $this->symbolAfter];
    }
}
