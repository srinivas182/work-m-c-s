<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property Carbon|null $deleted_at
 * @property list<string>|null $deleted_product_ids
 */
final class ShopCategory extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes; // recently deleted, 30 days (16.6b)

    protected function casts(): array
    {
        return ['deleted_product_ids' => 'array'];
    }

    protected $table = 'shop_categories';

    protected $guarded = [];
}
