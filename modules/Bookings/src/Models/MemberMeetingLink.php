<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $member_id
 * @property string $url
 */
final class MemberMeetingLink extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'member_id';

    protected $keyType = 'string';

    protected $guarded = [];
}
