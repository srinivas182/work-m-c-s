<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $page_version_id
 * @property string|null $path
 * @property Carbon|null $rendered_at
 * @property string|null $error
 * @property int $attempts
 */
final class PageRender extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'page_version_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rendered_at' => 'datetime', 'attempts' => 'integer'];
    }
}
