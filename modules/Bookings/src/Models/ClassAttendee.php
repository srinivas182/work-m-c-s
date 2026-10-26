<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $session_id
 * @property string $status
 * @property string $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string $source
 * @property int $deposit_cents
 * @property string|null $payment_reference
 * @property Carbon|null $hold_expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 */
final class ClassAttendee extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Statuses that take a place (held while paying counts, so a class can't be oversold). */
    public const HOLDING = ['confirmed', 'pending_payment'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deposit_cents' => 'integer', 'hold_expires_at' => 'datetime', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function session(): ClassSession
    {
        return ClassSession::query()->whereKey($this->session_id)->firstOrFail();
    }
}
