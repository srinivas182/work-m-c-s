<?php

declare(strict_types=1);

namespace Wurk\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * WURK's own account with a provider (central).
 *
 * @property string $provider
 * @property string $mode
 * @property array<string, string> $credentials the current mode's keys (read-only)
 * @property array<string, string>|null $test_credentials
 * @property array<string, string>|null $live_credentials
 * @property string|null $webhook_secret
 * @property string|null $webhook_id
 * @property string|null $label
 * @property bool $active
 */
final class PlatformPaymentAccount extends Model implements CentralModel
{
    use HasPaymentKeys;

    public $incrementing = false;

    protected $primaryKey = 'provider';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['test_credentials', 'live_credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return ['test_credentials' => 'encrypted:array', 'live_credentials' => 'encrypted:array', 'webhook_secret' => 'encrypted', 'active' => 'boolean'];
    }
}
