<?php

declare(strict_types=1);

namespace Wurk\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $identity_id
 * @property string $secret
 * @property list<string>|null $recovery_codes hashed
 * @property Carbon|null $confirmed_at
 */
final class IdentityMfa extends Model implements CentralModel
{
    protected $table = 'identity_mfa';

    protected $primaryKey = 'identity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['identity_id', 'type', 'secret', 'recovery_codes', 'confirmed_at'];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'recovery_codes' => 'encrypted:array', 'confirmed_at' => 'datetime'];
    }
}
