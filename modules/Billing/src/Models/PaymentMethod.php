<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $gateway
 * @property string $authorization
 * @property string $signature
 * @property string|null $brand
 * @property string|null $last4
 * @property string|null $expires
 */
final class PaymentMethod extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['authorization'];

    protected function casts(): array
    {
        return ['authorization' => 'encrypted'];
    }
}
