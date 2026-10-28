<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $member_id
 * @property string $council
 * @property string $number
 */
final class MemberRegistration extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'member_id';

    protected $keyType = 'string';

    protected $guarded = [];
}
