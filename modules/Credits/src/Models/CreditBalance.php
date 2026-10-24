<?php

declare(strict_types=1);

namespace Wurk\Credits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * Cached balance, kept in step with the ledger inside the same transaction.
 *
 * @property string $tenant_id
 * @property int $balance_milli
 * @property int $allowance_milli
 * @property int $low_balance_milli
 * @property int|null $monthly_limit_milli
 * @property array<string, int>|null $staff_limits
 * @property Carbon|null $low_alerted_at
 */
final class CreditBalance extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = null;

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['staff_limits' => 'array', 'balance_milli' => 'integer', 'allowance_milli' => 'integer', 'low_balance_milli' => 'integer', 'low_alerted_at' => 'datetime'];
    }
}
