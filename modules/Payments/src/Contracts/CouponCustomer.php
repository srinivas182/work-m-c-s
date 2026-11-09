<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/** One-way key for a customer from their phone number, for once-per-customer coupons (15.3b) — the number itself isn't kept. */
final class CouponCustomer
{
    public static function key(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        return $digits === '' ? null : sha1('coupon|'.substr($digits, -9));
    }
}
