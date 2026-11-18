<?php

declare(strict_types=1);

namespace Wurk\Regions;

use Wurk\Platform\Contracts\BusinessCurrency;
use Wurk\Platform\Money\Currency;
use Wurk\Regions\Models\Country;
use Wurk\Tenancy\Contracts\TenantContext;

/** The business's currency from its country pack (25.1b), remembered per country for the request; rand outside a business. */
final class PackCurrency implements BusinessCurrency
{
    /** @var array<string, Currency> */
    private array $byCountry = [];

    public function __construct(private TenantContext $context) {}

    public function current(): Currency
    {
        if (! $this->context->has()) {
            return Currency::zar();
        }
        $code = $this->context->current()->countryCode() ?: 'ZA';

        return $this->byCountry[$code] ??= $this->load($code);
    }

    private function load(string $code): Currency
    {
        $c = Country::query()->find($code);

        return $c === null ? Currency::zar() : new Currency($c->currency, $c->currency_symbol, $c->currency_decimals, $c->decimal_separator,
            $c->thousands_separator, $c->symbol_after);
    }
}
