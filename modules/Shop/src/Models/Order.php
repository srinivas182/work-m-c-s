<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Access\Contracts\BelongsToBranch;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Tenancy\Contracts\BelongsToTenant;
use Wurk\Tenancy\Contracts\BranchDirectory;

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
 * @property string|null $branch_id
 * @property string|null $shipping_provider
 * @property string|null $shipping_service
 * @property string|null $pickup_point
 * @property string|null $pickup_point_name
 * @property array<string, string|null>|null $shipping_to
 * @property string|null $tracking_reference
 * @property string|null $tracking_url
 * @property string|null $label_url
 */
final class Order extends Model
{
    use BelongsToBranch;
    use BelongsToTenant;
    use HasUlids;

    /** Orders still holding stock (not yet paid, cancelled or expired). */
    public const HOLDING = ['awaiting_payment'];

    protected $table = 'shop_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['shipping_to' => 'array', 'number' => 'integer', 'subtotal_cents' => 'integer', 'delivery_cents' => 'integer', 'total_cents' => 'integer', 'hold_expires_at' => 'datetime', 'refunded_cents' => 'integer', 'paid_at' => 'datetime'];
    }

    /** Anything created without a branch belongs to the business's main branch. */
    protected static function booted(): void
    {
        self::creating(function (self $order): void {
            $order->branch_id ??= app(BranchDirectory::class)->main();
        });
        // Everyone who orders becomes (or is matched to) a contact.
        self::created(function (self $o): void {
            app(ContactBook::class)->remember($o->customer_name, $o->customer_phone, $o->customer_email, 'order', $o->id, $o->branch_id, "Order #{$o->number}");
        });
    }
}
