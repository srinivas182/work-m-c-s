<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $order_id
 * @property string $variant_id
 * @property string $product_name
 * @property string|null $variant_label
 * @property int $unit_cents
 * @property int $quantity
 * @property bool $stock_tracked
 * @property int $line_cents
 */
final class OrderItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'shop_order_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_cents' => 'integer', 'quantity' => 'integer', 'stock_tracked' => 'boolean', 'line_cents' => 'integer'];
    }
}
