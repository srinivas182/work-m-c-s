<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property array<int|string, string> $variants
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 */
final class SiteAsset extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['variants' => 'array', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer'];
    }
}
