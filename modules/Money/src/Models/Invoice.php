<?php

declare(strict_types=1);

namespace Wurk\Money\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * A simple invoice (Sprint 23.5a).
 *
 * @property string $id
 * @property int $number
 * @property string $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property list<array{description: string, quantity: int, unit_cents: int}> $lines
 * @property int $vat_bp
 * @property int $refunded_cents
 * @property string|null $vat_number
 * @property int $subtotal_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property string $status
 * @property string|null $payment_reference
 * @property Carbon|null $sent_at
 * @property string|null $sent_by
 * @property Carbon|null $paid_at
 * @property string|null $paid_how
 * @property int $reminders_sent
 * @property Carbon|null $last_reminded_at
 * @property bool $reminders_on
 * @property string|null $created_by
 * @property Carbon $created_at
 */
final class Invoice extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'money_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lines' => 'array', 'issued_on' => 'date', 'due_on' => 'date', 'sent_at' => 'datetime', 'paid_at' => 'datetime', 'last_reminded_at' => 'datetime',
            'reminders_on' => 'boolean', 'number' => 'integer', 'vat_bp' => 'integer', 'subtotal_cents' => 'integer', 'vat_cents' => 'integer', 'total_cents' => 'integer', 'reminders_sent' => 'integer', 'refunded_cents' => 'integer'];
    }

    public function label(): string
    {
        return 'INV-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }
}
