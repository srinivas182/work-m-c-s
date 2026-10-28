<?php

declare(strict_types=1);

namespace Wurk\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A payment to WURK (central).
 *
 * @property string $reference
 * @property string $provider
 * @property string|null $provider_id
 * @property int $amount_cents
 * @property string $status
 * @property array{action: string, fields: array<string, string>}|null $form
 * @property Carbon|null $paid_at
 */
final class PlatformPaymentAttempt extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'datetime', 'form' => 'array'];
    }
}
