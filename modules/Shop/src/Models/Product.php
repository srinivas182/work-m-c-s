<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string|null $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property list<string>|null $options
 * @property list<string>|null $photos
 * @property string $status
 * @property int $position
 */
final class Product extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'shop_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'photos' => 'array', 'position' => 'integer'];
    }
}
