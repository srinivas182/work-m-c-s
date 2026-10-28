<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
 * @property string|null $address
 * @property string|null $phone
 * @property Carbon|null $closed_at
 */
final class Branch extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['name', 'code', 'timezone', 'is_default', 'address', 'phone', 'closed_at'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'is_default' => 'boolean'];
    }
}
