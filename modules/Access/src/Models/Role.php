<?php

declare(strict_types=1);

namespace Wurk\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * Custom business role. Key used in assignments: "custom:{id}".
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property list<string> $permissions
 */
final class Role extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['name', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function key(): string
    {
        return 'custom:'.$this->id;
    }
}
