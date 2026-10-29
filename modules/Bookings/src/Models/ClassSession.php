<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Access\Contracts\BelongsToBranch;
use Wurk\Tenancy\Contracts\BelongsToTenant;
use Wurk\Tenancy\Contracts\BranchDirectory;

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
 * @property bool $pay_online
 * @property string|null $branch_id
 */
final class ClassSession extends Model
{
    use BelongsToBranch;
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer', 'price_cents' => 'integer', 'pay_online' => 'boolean'];
    }

    /** Places taken (confirmed, or held while paying). */
    public function taken(): int
    {
        return ClassAttendee::query()->where('session_id', $this->id)->whereIn('status', ClassAttendee::HOLDING)->count();
    }

    /** Anything created without a branch belongs to the business's main branch (as existing rows were moved). */
    protected static function booted(): void
    {
        self::creating(function (self $row): void {
            $row->branch_id ??= app(BranchDirectory::class)->main();
        });
    }
}
