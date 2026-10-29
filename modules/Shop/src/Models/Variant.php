<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Wurk\Tenancy\Contracts\BelongsToTenant;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * @property string $id
 * @property string $product_id
 * @property array<string, string>|null $values
 * @property string|null $label
 * @property string|null $sku
 * @property int $price_cents
 * @property int|null $compare_at_cents
 * @property int|null $stock
 * @property int $position
 */
final class Variant extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'shop_variants';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['values' => 'array', 'price_cents' => 'integer', 'compare_at_cents' => 'integer', 'stock' => 'integer', 'position' => 'integer'];
    }

    /** Created with stock but no branch stock (tests, demo data, imports): it's held at the main branch. */
    protected static function booted(): void
    {
        self::created(function (self $variant): void {
            if ($variant->stock !== null) {
                DB::connection($variant->getConnectionName())->table('shop_stock')->insert([
                    'variant_id' => $variant->id, 'branch_id' => app(BranchDirectory::class)->main(), 'tenant_id' => app(TenantContext::class)->id(), 'quantity' => $variant->stock,
                ]);
            }
        });
    }
}
