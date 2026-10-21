<?php

declare(strict_types=1);

namespace Wurk\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property string $member_id
 * @property string $role_key
 */
final class MemberRole extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'member_id';

    protected $fillable = ['member_id', 'role_key'];
}
