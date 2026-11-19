<?php

declare(strict_types=1);

namespace Wurk\Regions\Contracts;

/**
 * Countries WURK operates in, each with its pack (25.1): currency and how money is written, the tax and its dated rates,
 * the phone format, week start, languages, payment and messaging providers, and the legal templates that apply.
 *
 * @phpstan-type Tax array{name: string, rate_bp: int, levies: list<array{name: string, bp: int}>, total_bp: int, starts_on: string, confirmed: bool}
 * @phpstan-type Pack array{code: string, name: string, enabled: bool, currency: string, currency_symbol: string, currency_decimals: int,
 *   decimal_separator: string, thousands_separator: string, symbol_after: bool, tax: Tax, prices_include_tax: bool, phone_prefix: string,
 *   phone_digits: int, timezone: string, week_starts_on: int, languages: list<string>, payment_providers: list<string>, sms_provider: string|null,
 *   ussd_provider: string|null, returns_template: string}
 */
interface Countries
{
    /**
     * Countries open for sign-up. `vat_bp` is today's tax, levies included.
     *
     * @return list<array{code: string, name: string, currency: string, vat_bp: int, phone_prefix: string, timezone: string}>
     */
    public function enabled(): array;

    /** @return array{code: string, name: string, currency: string, vat_bp: int, phone_prefix: string, timezone: string, enabled: bool}|null */
    public function find(string $code): ?array;

    /** @return Pack|null */
    public function pack(string $code): ?array;

    /**
     * The tax on a day: the latest confirmed rate started by then (or, for a country not yet confirmed, its latest rate).
     *
     * @return Tax|null
     */
    public function taxOn(string $code, \DateTimeInterface $on): ?array;
}
