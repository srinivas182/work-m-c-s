<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $number
 * @property string $invoice_id
 * @property string $tenant_id
 * @property Carbon $issued_at
 * @property string $reason
 * @property int $excl_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property string $refund
 * @property string $refund_status
 */
final class CreditNote extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'excl_cents' => 'integer', 'vat_cents' => 'integer', 'total_cents' => 'integer'];
    }
}
