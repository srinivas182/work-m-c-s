<?php

declare(strict_types=1);

namespace Wurk\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property bool $collection_enabled
 * @property string|null $collection_address
 * @property bool $delivery_enabled
 * @property int $delivery_fee_cents
 * @property string|null $delivery_note
 * @property bool $offline_enabled
 * @property string|null $offline_instructions
 * @property int $offline_hold_hours
 * @property int $next_order_number
 */
final class ShopSettings extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $table = 'shop_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['collection_enabled' => 'boolean', 'delivery_enabled' => 'boolean', 'offline_enabled' => 'boolean',
            'delivery_fee_cents' => 'integer', 'offline_hold_hours' => 'integer', 'next_order_number' => 'integer'];
    }
}
