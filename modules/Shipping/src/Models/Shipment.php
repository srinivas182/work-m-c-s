<?php

declare(strict_types=1);

namespace Wurk\Shipping\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $reference
 * @property string $provider
 * @property string|null $account_branch_id
 * @property string|null $service
 * @property string|null $pickup_point
 * @property string|null $provider_id
 * @property string|null $tracking_reference
 * @property string|null $tracking_url
 * @property string|null $label_url
 * @property string $status
 */
final class Shipment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];
}
