<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $session_id
 * @property string $status
 * @property string $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string $source
 */
final class ClassAttendee extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    public function session(): ClassSession
    {
        return ClassSession::query()->whereKey($this->session_id)->firstOrFail();
    }
}
