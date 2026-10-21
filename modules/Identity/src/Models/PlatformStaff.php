<?php

declare(strict_types=1);

namespace Wurk\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $identity_id
 * @property string $status
 */
final class PlatformStaff extends Model implements CentralModel
{
    protected $table = 'platform_staff';

    protected $primaryKey = 'identity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['identity_id', 'status'];
}
