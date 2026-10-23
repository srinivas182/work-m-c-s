<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $category_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $position
 * @property string $status
 * @property string $draft
 * @property int $draft_revision
 * @property int|null $published_version
 * @property-read SiteCategory|null $category
 */
final class SiteTemplate extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['draft_revision' => 'integer', 'published_version' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<SiteCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SiteCategory::class, 'category_id');
    }
}
