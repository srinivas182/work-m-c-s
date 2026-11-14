<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\CannotRestore;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopCategory;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\CatalogChanged;

/**
 * Recently deleted products and categories (Sprint 16.6b). A product comes back with the variants deleted with it,
 * and stock as it was; a category with the products that were in it. Their web addresses stay reserved meanwhile.
 */
final readonly class DeletedCatalog
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function deleteProduct(Product $product): void
    {
        DB::connection($this->context->connectionName())->transaction(function () use ($product): void {
            $at = now();
            Variant::query()->where('product_id', $product->id)->update(['deleted_at' => $at]);
            $product->deleted_at = $at;
            $product->save();
        });
        $this->audit->record('shop.product_deleted', 'product', $product->id, ['name' => $product->name, 'restorable_for_days' => 30]);
        event(new CatalogChanged($this->context->id()));
    }

    public function restoreProduct(string $id): string
    {
        $p = Product::onlyTrashed()->whereKey($id)->first() ?? throw new CannotRestore('That product isn’t in Recently deleted any more.');
        DB::connection($this->context->connectionName())->transaction(function () use ($p): void {
            Variant::onlyTrashed()->where('product_id', $p->id)->where('deleted_at', $p->deleted_at)->update(['deleted_at' => null]);
            $p->restore();
        });
        $this->audit->record('shop.product_restored', 'product', $p->id, ['name' => $p->name]);
        event(new CatalogChanged($this->context->id()));

        return "“{$p->name}” is back in your shop, with its options and stock.";
    }

    public function purgeProducts(\DateTimeInterface $before): int
    {
        $ids = Product::onlyTrashed()->where('deleted_at', '<', $before)->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }
        $db = DB::connection($this->context->connectionName());
        $variants = Variant::withTrashed()->whereIn('product_id', $ids)->pluck('id')->all();
        $db->transaction(function () use ($db, $ids, $variants): void {
            $db->table('shop_stock')->where('tenant_id', $this->context->id())->whereIn('variant_id', $variants)->delete();
            $db->table('shop_variant_prices')->where('tenant_id', $this->context->id())->whereIn('variant_id', $variants)->delete();
            Variant::withTrashed()->whereIn('id', $variants)->forceDelete();
            Product::onlyTrashed()->whereIn('id', $ids)->forceDelete(); // past orders keep their own copy of the name and price
        });

        return count($ids);
    }

    public function deleteCategory(ShopCategory $category): void
    {
        DB::connection($this->context->connectionName())->transaction(function () use ($category): void {
            $products = Product::query()->where('category_id', $category->id)->pluck('id')->all();
            Product::query()->whereIn('id', $products)->update(['category_id' => null]);
            $category->deleted_product_ids = array_values(array_map('strval', $products));
            $category->deleted_at = now();
            $category->save();
        });
        event(new CatalogChanged($this->context->id()));
    }

    public function restoreCategory(string $id): string
    {
        $c = ShopCategory::onlyTrashed()->whereKey($id)->first() ?? throw new CannotRestore('That category isn’t in Recently deleted any more.');
        DB::connection($this->context->connectionName())->transaction(function () use ($c): void {
            // Only products still without a category go back into it (any moved since stay where they are).
            Product::query()->whereIn('id', $c->deleted_product_ids ?? [])->whereNull('category_id')->update(['category_id' => $c->id]);
            $c->deleted_product_ids = null;
            $c->restore();
        });
        event(new CatalogChanged($this->context->id()));

        return "Category “{$c->name}” is back, with its products.";
    }

    public function purgeCategories(\DateTimeInterface $before): int
    {
        return (int) ShopCategory::onlyTrashed()->where('deleted_at', '<', $before)->forceDelete();
    }
}
