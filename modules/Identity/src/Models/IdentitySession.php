<?php

declare(strict_types=1);

namespace Wurk\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A signed-in device. Lets people see where they are signed in and sign out
 * other devices, independent of the session storage driver.
 *
 * @property string $id hash of the session id
 * @property string $identity_id
 * @property string $surface admin|app
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $last_active_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
final class IdentitySession extends Model implements CentralModel
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'identity_id', 'surface', 'ip', 'user_agent', 'last_active_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
