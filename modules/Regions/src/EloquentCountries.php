<?php

declare(strict_types=1);

namespace Wurk\Regions;

use Wurk\Regions\Contracts\Countries;
use Wurk\Regions\Models\Country;

final class EloquentCountries implements Countries
{
    public function enabled(): array
    {
        return array_values(Country::query()->where('enabled', true)->orderBy('name')->get()
            ->map(fn (Country $c) => $c->only(['code', 'name', 'currency', 'vat_bp', 'phone_prefix', 'timezone']))->all());
    }

    public function find(string $code): ?array
    {
        /** @var array{code: string, name: string, currency: string, vat_bp: int, phone_prefix: string, timezone: string, enabled: bool}|null */
        return Country::query()->find(strtoupper($code))?->only(['code', 'name', 'currency', 'vat_bp', 'phone_prefix', 'timezone', 'enabled']);
    }
}
