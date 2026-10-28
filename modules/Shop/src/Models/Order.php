<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property int $number
 * @property string $status
 * @property string $payment_method
 * @property string $fulfilment
 * @property string $customer_name
 * @property string $customer_phone
 * @property string|null $customer_email
 * @property string|null $delivery_address
 * @property string|null $notes
 * @property int $subtotal_cents
 * @property int $delivery_cents
 * @property int $total_cents
 * @property Carbon|null $hold_expires_at
 * @property int $refunded_cents
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 */
final class Order extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Orders still holding stock (not yet paid, cancelled or expired). */
    public const HOLDING = ['awaiting_payment'];

    protected $table = 'shop_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['number' => 'integer', 'subtotal_cents' => 'integer', 'delivery_cents' => 'integer', 'total_cents' => 'integer', 'hold_expires_at' => 'datetime', 'refunded_cents' => 'integer', 'paid_at' => 'datetime'];
    }
}
