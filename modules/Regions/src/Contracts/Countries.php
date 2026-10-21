<?php

declare(strict_types=1);

namespace Wurk\Regions\Contracts;

/** Countries WURK operates in (currency, VAT, phone prefix, time zone). */
interface Countries
{
    /** @return list<array{code: string, name: string, currency: string, vat_bp: int, phone_prefix: string, timezone: string}> */
    public function enabled(): array;

    /** @return array{code: string, name: string, currency: string, vat_bp: int, phone_prefix: string, timezone: string, enabled: bool}|null */
    public function find(string $code): ?array;
}
