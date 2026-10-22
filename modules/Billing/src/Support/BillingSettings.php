<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Wurk\Security\Contracts\PlatformSettings;

/**
 * Invoice issuer details and the dunning schedule (PRD §11.1), editable by WURK
 * staff. The issuer defaults are placeholders: issued tax invoices are
 * immutable, so set the real legal details before invoicing real customers.
 */
final readonly class BillingSettings
{
    public const DEFAULT_ISSUER = [
        'name' => 'WURK (Pty) Ltd',
        'address' => '1 Placeholder Street, Johannesburg, 2000, South Africa',
        'vat_number' => '4000000000',
        'registration' => '2026/000000/07',
    ];

    public const DEFAULT_DUNNING = ['retry_days' => [3, 5, 7], 'restrict_from_day' => 8, 'suspend_on_day' => 15];

    public function __construct(private PlatformSettings $settings) {}

    /** @return array{name: string, address: string, vat_number: string, registration: string} */
    public function issuer(): array
    {
        /** @var array{name: string, address: string, vat_number: string, registration: string} */
        return [...self::DEFAULT_ISSUER, ...array_filter((array) $this->settings->get('invoice_issuer', []), fn ($v) => is_string($v) && $v !== '')];
    }

    public function issuerIsPlaceholder(): bool
    {
        $issuer = $this->issuer();

        return $issuer['vat_number'] === self::DEFAULT_ISSUER['vat_number'] || $issuer['address'] === self::DEFAULT_ISSUER['address'];
    }

    /** @return array{retry_days: list<int>, restrict_from_day: int, suspend_on_day: int} */
    public function dunning(): array
    {
        /** @var array{retry_days: list<int>, restrict_from_day: int, suspend_on_day: int} */
        return [...self::DEFAULT_DUNNING, ...(array) $this->settings->get('dunning', [])];
    }

    /** @param  array<string, mixed>  $issuer */
    public function setIssuer(array $issuer, ?string $by): void
    {
        $this->settings->set('invoice_issuer', $issuer, $by);
    }

    /** @param  array{retry_days: list<int>, restrict_from_day: int, suspend_on_day: int}  $dunning */
    public function setDunning(array $dunning, ?string $by): void
    {
        $this->settings->set('dunning', $dunning, $by);
    }
}
