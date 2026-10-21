<?php

declare(strict_types=1);

namespace Wurk\Plans\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $tenant_id
 * @property string $module_key
 * @property bool $granted
 * @property string $reason
 */
final class EntitlementOverride extends Model implements CentralModel
{
    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'module_key', 'granted', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }
}
