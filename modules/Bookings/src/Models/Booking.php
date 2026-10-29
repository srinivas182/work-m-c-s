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
 * @property string $service_id
 * @property string $member_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property string $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string|null $notes
 * @property string $source
 * @property int $deposit_cents
 * @property bool $online
 * @property string|null $meeting_provider
 * @property string|null $meeting_id
 * @property string|null $meeting_url
 * @property string|null $payment_reference
 * @property Carbon|null $hold_expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property string|null $consent_version
 * @property Carbon|null $consent_at
 * @property string|null $branch_id
 */
final class Booking extends Model
{
    use BelongsToBranch;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'bookings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'deposit_cents' => 'integer', 'online' => 'boolean', 'hold_expires_at' => 'datetime', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    /** Statuses that hold their time (claim slots). */
    public const ACTIVE = ['pending_payment', 'confirmed'];

    /** Anything created without a branch belongs to the business's main branch (as existing rows were moved). */
    protected static function booted(): void
    {
        self::creating(function (self $row): void {
            $row->branch_id ??= app(BranchDirectory::class)->main();
        });
    }
}
