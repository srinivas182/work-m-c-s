<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Payments\Contracts\Coupons;
use Wurk\Tenancy\Contracts\TenantContext;

/** The business's coupon codes (Sprint 15.3b). Uses count only while the order or booking stands; releases give them back. */
final readonly class CouponBook implements Coupons
{
    public function __construct(private TenantContext $context) {}

    public function quote(string $code, int $amountCents, string $for, ?string $customerKey): array
    {
        $no = fn (string $why) => ['ok' => false, 'coupon_id' => null, 'discount_cents' => 0, 'message' => $why];
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $c = $db->table('coupons')->where('tenant_id', $t)->where('code', strtoupper(trim($code)))->first();
        if ($c === null || ! $c->active || ($c->applies_to !== 'both' && $c->applies_to !== $for)) {
            return $no('That code isn’t valid.');
        }
        if (($c->starts_at !== null && now()->lt($c->starts_at)) || ($c->ends_at !== null && now()->gt($c->ends_at))) {
            return $no('That code has expired.');
        }
        if ($amountCents < (int) $c->min_spend_cents) {
            return $no('That code needs a spend of at least R'.number_format($c->min_spend_cents / 100, 2).'.');
        }
        $used = $db->table('coupon_redemptions')->where('tenant_id', $t)->where('coupon_id', $c->id)->whereNull('released_at');
        if ($c->max_uses !== null && (clone $used)->count() >= (int) $c->max_uses) {
            return $no('That code has been used up.');
        }
        if ($c->once_per_customer && $customerKey !== null && (clone $used)->where('customer_key', $customerKey)->exists()) {
            return $no('You’ve already used that code.');
        }
        $discount = $c->kind === 'percent' ? intdiv($amountCents * (int) $c->value, 10000) : (int) $c->value;

        return ['ok' => true, 'coupon_id' => (string) $c->id, 'discount_cents' => min($amountCents, $discount), 'message' => null];
    }

    public function redeem(string $couponId, string $sourceType, string $sourceId, int $discountCents, ?string $customerKey): void
    {
        DB::connection($this->context->connectionName())->table('coupon_redemptions')->insertOrIgnore(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(),
            'coupon_id' => $couponId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'discount_cents' => $discountCents, 'customer_key' => $customerKey, 'created_at' => now()]);
    }

    public function release(string $sourceType, string $sourceId): void
    {
        DB::connection($this->context->connectionName())->table('coupon_redemptions')->where('tenant_id', $this->context->id())
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->whereNull('released_at')->update(['released_at' => now()]);
    }
}
