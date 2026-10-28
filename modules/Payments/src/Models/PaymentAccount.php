<?php

declare(strict_types=1);

namespace Wurk\Payments\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
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
final class PaymentAccount extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['test_credentials', 'live_credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return ['test_credentials' => 'encrypted:array', 'live_credentials' => 'encrypted:array', 'webhook_secret' => 'encrypted', 'active' => 'boolean'];
    }

    /**
     * The keys for the current mode (test or live) — what the gateways use.
     *
     * @return Attribute<array<string, string>, never>
     */
    protected function credentials(): Attribute
    {
        return Attribute::get(fn (): array => (array) (($this->mode === 'live' ? $this->live_credentials : $this->test_credentials) ?? []));
    }

    /** Whether keys are saved for a mode. */
    public function hasKeys(string $mode): bool
    {
        return (($mode === 'live' ? $this->live_credentials : $this->test_credentials) ?? []) !== [];
    }
}
