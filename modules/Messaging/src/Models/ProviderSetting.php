<?php

declare(strict_types=1);

namespace Wurk\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $channel
 * @property string $driver
 * @property array<string, string> $credentials
 * @property array<string, string>|null $settings
 * @property bool $active
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 */
final class ProviderSetting extends Model implements CentralModel
{
    protected $primaryKey = 'channel';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['channel', 'driver', 'credentials', 'settings', 'active', 'last_tested_at', 'last_test_ok', 'last_test_message', 'updated_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'settings' => 'array', 'active' => 'boolean', 'last_test_ok' => 'boolean', 'last_tested_at' => 'datetime'];
    }
}
