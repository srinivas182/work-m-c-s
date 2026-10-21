<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * A business location. Every tenant has a default branch.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $code
 * @property string $timezone
 * @property bool $is_default
 */
final class Branch extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['name', 'code', 'timezone', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
