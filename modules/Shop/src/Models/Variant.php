<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

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
}
