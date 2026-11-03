<?php

declare(strict_types=1);

namespace Wurk\Shipping\Contracts;

/**
 * Turns an order's items into ONE parcel: the total weight, and the business's smallest box that fits — or, when
 * none fits, the courier's size-based weight and a warning. Used for checkout rates and for booking alike.
 */
interface ParcelPlanner
{
    /**
     * @param  list<array{weight_g: int|null, size_mm: array{0: int, 1: int, 2: int}|null, quantity: int}>  $items
     * @return array{weight_kg: float, size_cm: array{length: float, width: float, height: float}|null, box: string|null, fits: bool}
     */
    public function plan(array $items): array;
}
