<?php

declare(strict_types=1);

namespace Wurk\Regions;

use Carbon\CarbonImmutable;
use Wurk\Regions\Contracts\Countries;
use Wurk\Regions\Models\Country;
use Wurk\Regions\Models\TaxRate;

final class EloquentCountries implements Countries
{
    public function enabled(): array
    {
        return array_values(Country::query()->where('enabled', true)->orderBy('name')->get()
            ->map(fn (Country $c) => $c->only(['code', 'name', 'currency', 'phone_prefix', 'timezone']) + ['vat_bp' => $this->totalToday($c)])
            ->map(fn (array $c) => ['code' => $c['code'], 'name' => $c['name'], 'currency' => $c['currency'], 'vat_bp' => $c['vat_bp'], 'phone_prefix' => $c['phone_prefix'], 'timezone' => $c['timezone']])
            ->all());
    }

    public function find(string $code): ?array
    {
        $c = Country::query()->find(strtoupper($code));

        return $c === null ? null : ['code' => $c->code, 'name' => $c->name, 'currency' => $c->currency, 'vat_bp' => $this->totalToday($c),
            'phone_prefix' => $c->phone_prefix, 'timezone' => $c->timezone, 'enabled' => $c->enabled];
    }

    public function pack(string $code): ?array
    {
        $c = Country::query()->find(strtoupper($code));
        if ($c === null) {
            return null;
        }
        $tax = $this->taxOn($c->code, CarbonImmutable::now($c->timezone)) ?? ['name' => $c->tax_name, 'rate_bp' => $c->vat_bp, 'levies' => [], 'total_bp' => $c->vat_bp, 'starts_on' => '2026-01-01', 'confirmed' => false];

        return ['code' => $c->code, 'name' => $c->name, 'enabled' => $c->enabled, 'currency' => $c->currency, 'currency_symbol' => $c->currency_symbol,
            'currency_decimals' => $c->currency_decimals, 'decimal_separator' => $c->decimal_separator, 'thousands_separator' => $c->thousands_separator,
            'symbol_after' => $c->symbol_after, 'tax' => $tax, 'prices_include_tax' => $c->prices_include_tax, 'phone_prefix' => $c->phone_prefix,
            'phone_digits' => $c->phone_digits, 'timezone' => $c->timezone, 'week_starts_on' => $c->week_starts_on, 'languages' => array_values($c->languages ?? ['en']),
            'payment_providers' => array_values($c->payment_providers ?? []), 'sms_provider' => $c->sms_provider, 'ussd_provider' => $c->ussd_provider,
            'returns_template' => $c->returns_template];
    }

    public function taxOn(string $code, \DateTimeInterface $on): ?array
    {
        $code = strtoupper($code);
        $day = CarbonImmutable::instance($on)->toDateString();
        $started = TaxRate::query()->where('country_code', $code)->where('starts_on', '<=', $day)->orderByDesc('starts_on');
        $rate = (clone $started)->whereNotNull('confirmed_at')->first() ?? $started->first();
        if ($rate === null) {
            return null;
        }
        $name = (string) (Country::query()->whereKey($code)->value('tax_name') ?? 'VAT');

        return ['name' => $name, 'rate_bp' => $rate->rate_bp, 'levies' => array_values(array_map(fn (array $l) => ['name' => (string) $l['name'], 'bp' => (int) $l['bp']], $rate->levies ?? [])),
            'total_bp' => $rate->totalBp(), 'starts_on' => $rate->startsOn(), 'confirmed' => $rate->confirmed_at !== null];
    }

    private function totalToday(Country $c): int
    {
        return $this->taxOn($c->code, CarbonImmutable::now($c->timezone))['total_bp'] ?? $c->vat_bp;
    }
}
