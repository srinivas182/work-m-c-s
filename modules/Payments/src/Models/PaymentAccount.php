<?php

declare(strict_types=1);

namespace Wurk\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $provider
 * @property string $mode
 * @property array<string, string> $credentials
 * @property string|null $webhook_secret
 * @property string|null $webhook_id
 * @property string|null $label
 * @property bool $active
 */
final class PaymentAccount extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'webhook_secret' => 'encrypted', 'active' => 'boolean'];
    }
}
