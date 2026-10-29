<?php

declare(strict_types=1);

namespace Wurk\Shipping\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string|null $branch_id
 * @property string $provider
 * @property string $mode
 * @property bool $active
 * @property array<string, string>|null $test_credentials
 * @property array<string, string>|null $live_credentials
 * @property int|null $flat_rate_cents
 * @property string|null $label
 */
final class ShippingAccount extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected $hidden = ['test_credentials', 'live_credentials'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'test_credentials' => 'encrypted:array', 'live_credentials' => 'encrypted:array'];
    }

    /** @return array<string, string> the credentials for the account's current mode */
    public function credentials(): array
    {
        return ($this->mode === 'live' ? $this->live_credentials : $this->test_credentials) ?? [];
    }

    public function hasKeys(string $mode): bool
    {
        return (($mode === 'live' ? $this->live_credentials : $this->test_credentials) ?? []) !== [];
    }

    public function baseUrl(): string
    {
        return rtrim((string) config("shipping.{$this->provider}.{$this->mode}"), '/');
    }
}
