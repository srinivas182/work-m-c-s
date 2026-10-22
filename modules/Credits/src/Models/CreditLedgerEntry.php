<?php

declare(strict_types=1);

namespace Wurk\Credits\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $type
 * @property int $amount_milli
 * @property int $balance_after_milli
 * @property string $idempotency_key
 * @property string|null $channel
 * @property string $description
 * @property Carbon $created_at
 */
final class CreditLedgerEntry extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['amount_milli' => 'integer', 'balance_after_milli' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('The credit ledger is append-only.'));
        self::deleting(fn () => throw new \LogicException('The credit ledger is append-only.'));
    }
}
