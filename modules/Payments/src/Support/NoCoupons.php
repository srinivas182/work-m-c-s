<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Wurk\Payments\Contracts\Coupons;

/** Without the Campaigns module, no codes apply. */
final class NoCoupons implements Coupons
{
    public function quote(string $code, int $amountCents, string $for, ?string $customerKey): array
    {
        return ['ok' => false, 'coupon_id' => null, 'discount_cents' => 0, 'message' => 'That code isn’t valid.'];
    }

    public function redeem(string $couponId, string $sourceType, string $sourceId, int $discountCents, ?string $customerKey): void {}

    public function release(string $sourceType, string $sourceId): void {}
}
