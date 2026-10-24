<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $icon
 * @property int $position
 * @property bool $active
 */
final class SiteCategory extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }

    /** @return HasMany<SiteTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(SiteTemplate::class, 'category_id');
    }
}
