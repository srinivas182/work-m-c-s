<?php

declare(strict_types=1);

namespace Wurk\Reach\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * One USSD session (25.2): the provider's id, the caller's number and where they are in the menu.
 *
 * @property string $id
 * @property string $provider
 * @property string $phone
 * @property string|null $tenant_id
 * @property array<string, mixed> $state
 * @property int $steps
 * @property bool $ended
 * @property bool $charged
 * @property CarbonImmutable $updated_at
 */
final class UssdSession extends Model implements CentralModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'provider', 'phone', 'tenant_id', 'state', 'steps', 'ended', 'charged'];

    protected function casts(): array
    {
        return ['state' => 'array', 'steps' => 'integer', 'ended' => 'boolean', 'charged' => 'boolean', 'updated_at' => 'immutable_datetime'];
    }
}
