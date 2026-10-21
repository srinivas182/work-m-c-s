<?php

declare(strict_types=1);

namespace Wurk\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A person (WURK ID). Holds identity only — never business data.
 *
 * @property string $id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $password
 * @property string $status
 * @property Carbon|null $last_login_at
 */
final class Identity extends Authenticatable implements CentralModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'password', 'locale', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return HasOne<IdentityMfa, $this> */
    public function mfa(): HasOne
    {
        return $this->hasOne(IdentityMfa::class);
    }

    public function hasMfa(): bool
    {
        return $this->mfa()->whereNotNull('confirmed_at')->exists();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasOne<PlatformStaff, $this> */
    public function staff(): HasOne
    {
        return $this->hasOne(PlatformStaff::class);
    }

    public function isPlatformStaff(): bool
    {
        return $this->staff()->where('status', 'active')->exists();
    }
}
