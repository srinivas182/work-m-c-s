<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $number
 * @property string $tenant_id
 * @property string|null $payment_id
 * @property Carbon $issued_at
 * @property array{name: string, address: string, vat_number: string} $issuer
 * @property array{name: string, email: string, address: string|null, vat_number: string|null} $customer
 * @property string $currency
 * @property int $vat_bp
 * @property int $excl_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property int $credited_cents
 */
final class Invoice extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issuer' => 'array', 'customer' => 'array', 'issued_at' => 'datetime', 'excl_cents' => 'integer', 'vat_cents' => 'integer', 'total_cents' => 'integer', 'credited_cents' => 'integer', 'vat_bp' => 'integer'];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    protected static function booted(): void
    {
        // Tax invoices never change after issue; only the credited total may grow.
        self::updating(function (Invoice $invoice): void {
            if (array_diff(array_keys($invoice->getDirty()), ['credited_cents', 'updated_at']) !== []) {
                throw new \LogicException('Tax invoices are immutable; issue a credit note instead.');
            }
        });
        self::deleting(fn () => throw new \LogicException('Tax invoices cannot be deleted.'));
    }
}
