<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Every stock movement goes through here: tracked stock is kept per branch, and the variant's own stock is the
 * cached total (for product pages and "sold out"). Call take() and give() inside a transaction.
 */
final readonly class BranchStock
{
    public function __construct(private TenantContext $context, private BranchDirectory $branches) {}

    /** The branch that fulfils an order: the chosen branch for collection, the "ships from" branch for delivery. */
    public function fulfilling(string $fulfilment, ?string $chosen): string
    {
        $open = array_column($this->branches->open(), 'id');
        if ($fulfilment === 'collection' && $chosen !== null && in_array($chosen, $open, true)) {
            return $chosen;
        }
        $ships = ShopSettings::query()->find($this->context->id())?->ships_from_branch_id;

        return $fulfilment === 'delivery' && $ships !== null && in_array($ships, $open, true) ? $ships : $this->branches->main();
    }

    /** What's in stock at a branch (null = not tracked: unlimited). */
    public function at(Variant $variant, string $branchId): ?int
    {
        if ($variant->stock === null) {
            return null;
        }

        return (int) ($this->db()->table('shop_stock')->where('variant_id', $variant->id)->where('branch_id', $branchId)->value('quantity') ?? 0);
    }

    /** Take stock at a branch (locked); returns what was available if there isn't enough, otherwise null. */
    public function take(Variant $variant, string $branchId, int $quantity): ?int
    {
        if ($variant->stock === null) {
            return null; // not tracked
        }
        $have = (int) ($this->db()->table('shop_stock')->where('variant_id', $variant->id)->where('branch_id', $branchId)->lockForUpdate()->value('quantity') ?? 0);
        if ($have < $quantity) {
            return $have;
        }
        $this->db()->table('shop_stock')->where('variant_id', $variant->id)->where('branch_id', $branchId)->update(['quantity' => $have - $quantity]);
        $variant->update(['stock' => (int) $variant->stock - $quantity]); // the total

        return null;
    }

    /** Return stock to a branch (cancelled, expired, refunded with restock). */
    public function give(Variant $variant, string $branchId, int $quantity): void
    {
        if ($variant->stock === null) {
            return;
        }
        $row = $this->db()->table('shop_stock')->where('variant_id', $variant->id)->where('branch_id', $branchId);
        $row->clone()->exists()
            ? $row->increment('quantity', $quantity)
            : $this->db()->table('shop_stock')->insert(['variant_id' => $variant->id, 'branch_id' => $branchId, 'tenant_id' => $this->context->id(), 'quantity' => $quantity]);
        $variant->update(['stock' => (int) $variant->stock + $quantity]);
    }

    /**
     * Set a variant's stock per branch (the product editor); the total follows. Null = not tracked.
     *
     * @param  array<string, int>|null  $perBranch
     */
    public function set(Variant $variant, ?array $perBranch): void
    {
        $this->db()->table('shop_stock')->where('variant_id', $variant->id)->delete();
        if ($perBranch === null) {
            $variant->update(['stock' => null]);

            return;
        }
        foreach ($perBranch as $branchId => $quantity) {
            $this->db()->table('shop_stock')->insert(['variant_id' => $variant->id, 'branch_id' => $branchId, 'tenant_id' => $this->context->id(), 'quantity' => max(0, $quantity)]);
        }
        $variant->update(['stock' => array_sum(array_map(fn (int $q) => max(0, $q), $perBranch))]);
    }

    /**
     * Prices per branch (the product editor): a number overrides the variant's price there; empty = its own price.
     *
     * @param  array<string, int|string|null>  $prices
     */
    public function setPrices(Variant $variant, array $prices): void
    {
        $this->db()->table('shop_variant_prices')->where('variant_id', $variant->id)->delete();
        foreach ($prices as $branchId => $cents) {
            if ($cents !== null && $cents !== '') {
                $this->db()->table('shop_variant_prices')->insert(['variant_id' => $variant->id, 'branch_id' => (string) $branchId, 'tenant_id' => $this->context->id(), 'price_cents' => (int) $cents]);
            }
        }
    }

    /**
     * Stock per branch (tracked variants), for the product editor.
     *
     * @return array<string, int>
     */
    public function stockMap(Variant $variant): array
    {
        return $variant->stock === null ? [] : array_map('intval', $this->db()->table('shop_stock')->where('variant_id', $variant->id)->pluck('quantity', 'branch_id')->all());
    }

    /**
     * Price overrides per branch, for the product editor.
     *
     * @return array<string, int>
     */
    public function priceMap(Variant $variant): array
    {
        return array_map('intval', $this->db()->table('shop_variant_prices')->where('variant_id', $variant->id)->pluck('price_cents', 'branch_id')->all());
    }

    /** The variant's price at a branch (an override, or its own price). */
    public function priceAt(Variant $variant, ?string $branchId): int
    {
        $override = $branchId === null ? null : $this->db()->table('shop_variant_prices')->where('variant_id', $variant->id)->where('branch_id', $branchId)->value('price_cents');

        return $override === null ? $variant->price_cents : (int) $override;
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->context->connectionName());
    }
}
