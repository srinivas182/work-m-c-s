<?php

declare(strict_types=1);

namespace Wurk\Shipping\Contracts;

/** Couriers through the business's own accounts (a branch's own, else the business's). */
interface Couriers
{
    /**
     * What's set up for a branch: courier delivery (Bob Go) and pickup points (Pargo, flat rate).
     *
     * @return array{courier: bool, pickup_points: bool, pickup_rate_cents: int|null}
     */
    public function options(?string $branchId): array;

    /**
     * Live courier rates (Bob Go) from a branch to an address.
     *
     * @param  array{street: string, suburb?: string|null, city: string, postal_code: string}  $to
     * @return list<array{service: string, name: string, cents: int, eta: string|null}>
     */
    public function rates(?string $branchId, array $to, int $valueCents, float $weightKg): array;

    /**
     * The nearest Pargo pickup points to an address.
     *
     * @return list<array{code: string, name: string, address: string}>
     */
    public function pickupPoints(?string $branchId, string $near): array;

    /**
     * Create a shipment for an order; returns its tracking details.
     *
     * @param  array{provider: string, service?: string|null, pickup_point?: string|null, to?: array<string, string|null>, name: string, phone: string, email?: string|null, value_cents: int, weight_kg: float}  $details
     * @return array{tracking_reference: string|null, tracking_url: string|null, label_url: string|null}
     */
    public function ship(string $reference, ?string $branchId, array $details): array;
}
