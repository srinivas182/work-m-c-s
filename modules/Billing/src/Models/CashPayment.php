<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $payment_id
 * @property string $tenant_id
 * @property string $recorded_by
 * @property string $payer_name
 * @property string $receipt_number
 * @property string|null $notes
 * @property list<string> $approvers
 * @property string $code_hash
 * @property Carbon $code_expires_at
 * @property int $attempts
 * @property string $status
 * @property string|null $approved_by
 * @property Carbon|null $created_at
 */
final class CashPayment extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['approvers' => 'array', 'code_expires_at' => 'datetime', 'approved_at' => 'datetime', 'attempts' => 'integer'];
    }
}
