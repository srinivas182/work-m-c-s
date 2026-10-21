<?php

declare(strict_types=1);

namespace Wurk\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $identity_id
 * @property string $role_key
 */
final class PlatformRoleAssignment extends Model implements CentralModel
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'identity_id';

    protected $keyType = 'string';

    protected $fillable = ['identity_id', 'role_key'];
}
