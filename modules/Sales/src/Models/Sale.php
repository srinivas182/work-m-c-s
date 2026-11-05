<?php

declare(strict_types=1);

namespace Wurk\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string|null $contact_id
 * @property string|null $branch_id
 * @property int $amount_cents
 * @property string $method
 * @property string|null $note
 * @property string $status
 * @property string $recorded_by
 * @property string $recorded_by_name
 * @property string|null $device
 * @property string|null $decided_by_name
 * @property int $refunded_cents
 * @property Carbon $recorded_at
 * @property Carbon|null $decided_at
 */
final class Sale extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'refunded_cents' => 'integer', 'recorded_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
