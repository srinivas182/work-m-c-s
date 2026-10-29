<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Access\Contracts\BelongsToBranch;
use Wurk\Tenancy\Contracts\BelongsToTenant;
use Wurk\Tenancy\Contracts\BranchDirectory;

/**
 * @property string $id
 * @property string $member_id
 * @property int $weekday
 * @property string $starts
 * @property string $ends
 * @property string|null $branch_id
 */
final class BookingHours extends Model
{
    use BelongsToBranch;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'booking_hours';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public $timestamps = false;

    /** Anything created without a branch belongs to the business's main branch (as existing rows were moved). */
    protected static function booted(): void
    {
        self::creating(function (self $row): void {
            $row->branch_id ??= app(BranchDirectory::class)->main();
        });
    }
}
