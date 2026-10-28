<?php

declare(strict_types=1);

namespace Wurk\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $reference
 * @property string $provider
 * @property string|null $provider_id
 * @property int $amount_cents
 * @property string $status
 * @property Carbon|null $paid_at
 * @property array<string, string>|null $form
 */
final class PaymentAttempt extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'datetime', 'form' => 'array'];
    }
}
