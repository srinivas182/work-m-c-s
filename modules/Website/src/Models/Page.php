<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property string $slug
 * @property int $position
 * @property bool $is_home
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string $draft
 * @property int $draft_revision
 * @property string|null $published_version_id
 * @property string|null $updated_by
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Page extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_home' => 'boolean', 'draft_revision' => 'integer', 'position' => 'integer'];
    }
}
