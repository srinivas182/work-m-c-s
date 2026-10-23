<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property array<string, mixed> $branding
 * @property array<string, mixed> $menus
 * @property array<string, mixed> $settings
 */
final class Site extends Model
{
    use BelongsToTenant;

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['branding' => 'array', 'menus' => 'array', 'settings' => 'array'];
    }
}
