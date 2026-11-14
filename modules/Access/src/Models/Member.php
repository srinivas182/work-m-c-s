<?php

declare(strict_types=1);

namespace Wurk\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * A person on a business's team (business-side record of a WURK ID).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $identity_id
 * @property string $display_name
 * @property string $status
 * @property bool $all_branches
 */
final class Member extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['identity_id', 'display_name', 'status', 'all_branches'];

    protected function casts(): array
    {
        return ['all_branches' => 'boolean'];
    }

    /** @return HasMany<MemberRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(MemberRole::class);
    }

    /** @return HasMany<BranchMember, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(BranchMember::class);
    }

    /** @return list<string> */
    public function roleKeys(): array
    {
        // The loaded relation when there is one (the team page loads every member's roles in one query — 18.3a).
        $roles = $this->relationLoaded('roles') ? $this->roles : $this->roles()->get(['role_key']);

        return array_values(array_map('strval', $roles->pluck('role_key')->all()));
    }
}
