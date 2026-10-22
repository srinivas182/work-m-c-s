<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $purpose
 * @property string $reference
 * @property string $gateway
 * @property string $status
 * @property string $currency
 * @property int $amount_excl_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property int $vat_bp
 * @property array<string, mixed> $details
 * @property string|null $method_id
 * @property string|null $gateway_id
 * @property string|null $failure
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 */
final class Payment extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'paid_at' => 'datetime', 'amount_excl_cents' => 'integer', 'vat_cents' => 'integer', 'total_cents' => 'integer', 'vat_bp' => 'integer'];
    }
}
