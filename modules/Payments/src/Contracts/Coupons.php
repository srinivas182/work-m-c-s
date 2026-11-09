<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/**
 * Coupon codes (Sprint 15.3b) — applied to the goods at the shop's checkout and to booking deposits. Payments holds the
 * contract (both Shop and Bookings depend on it); the Campaigns module supplies the real coupon book, otherwise none apply.
 */
interface Coupons
{
    /**
     * What a code takes off an amount — nothing is reserved yet.
     *
     * @param  string  $for  shop | bookings
     * @param  string|null  $customerKey  one-way key for the customer (for once-per-customer codes)
     * @return array{ok: bool, coupon_id: string|null, discount_cents: int, message: string|null}
     */
    public function quote(string $code, int $amountCents, string $for, ?string $customerKey): array;

    /** Use a quoted coupon for an order or booking (counts towards its limits). */
    public function redeem(string $couponId, string $sourceType, string $sourceId, int $discountCents, ?string $customerKey): void;

    /** The order or booking was never paid or was cancelled: the use is given back. */
    public function release(string $sourceType, string $sourceId): void;
}
