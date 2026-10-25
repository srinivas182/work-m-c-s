<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $title
 * @property string|null $description
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $capacity
 * @property string $location_type
 * @property string|null $member_id
 * @property int $price_cents
 * @property string|null $meeting_provider
 * @property string|null $meeting_id
 * @property string|null $meeting_url
 * @property string $status
 */
final class ClassSession extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer', 'price_cents' => 'integer'];
    }

    /** Places taken (confirmed attendees). */
    public function taken(): int
    {
        return ClassAttendee::query()->where('session_id', $this->id)->where('status', 'confirmed')->count();
    }
}
