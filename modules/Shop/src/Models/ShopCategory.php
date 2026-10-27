<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property int $position
 */
final class ShopCategory extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'shop_categories';

    protected $guarded = [];
}
