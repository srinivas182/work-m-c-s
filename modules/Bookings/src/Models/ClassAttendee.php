<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Crm\Contracts\ContactBook;
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
 * @property string|null $consent_version
 * @property Carbon|null $consent_at
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
        return ['consent_at' => 'datetime', 'deposit_cents' => 'integer', 'hold_expires_at' => 'datetime', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function session(): ClassSession
    {
        return ClassSession::query()->whereKey($this->session_id)->firstOrFail();
    }

    /** Everyone who takes a class place becomes (or is matched to) a contact. */
    protected static function booted(): void
    {
        self::created(function (self $a): void {
            $session = ClassSession::query()->find($a->session_id);
            app(ContactBook::class)->remember($a->customer_name, $a->customer_phone, $a->customer_email, 'class', $a->id, $session?->branch_id, 'Class: '.($session->title ?? 'class'));
        });
    }
}
