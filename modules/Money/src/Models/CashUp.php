<?php

declare(strict_types=1);

namespace Wurk\Money\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * An end-of-day cash-up (Sprint 23.5b).
 *
 * @property string $id
 * @property Carbon $day
 * @property string|null $branch_id
 * @property array{cash: int, card_machine: int, qr: int} $expected
 * @property array{cash: int, card_machine: int, qr: int} $counted
 * @property int $difference_cents
 * @property string|null $reason
 * @property string $status
 * @property string $counted_by
 * @property string $counted_by_name
 * @property string|null $reopened_by
 * @property Carbon|null $reopened_at
 * @property Carbon $created_at
 */
final class CashUp extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'money_cashups';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['day' => 'date', 'expected' => 'array', 'counted' => 'array', 'difference_cents' => 'integer', 'reopened_at' => 'datetime'];
    }
}
