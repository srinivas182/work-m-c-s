<?php

declare(strict_types=1);

namespace Wurk\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A business's own WhatsApp number, connected through Meta's Embedded Signup (Sprint 21.1).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_id
 * @property string $phone_number_id
 * @property string|null $display_phone
 * @property string|null $verified_name
 * @property string $status
 * @property bool $coexistence
 * @property string|null $name_status
 * @property string|null $quality
 * @property string|null $messaging_limit
 * @property bool|null $payment_method
 * @property string|null $follow_up_status
 * @property string $token
 * @property string|null $pin
 * @property string|null $connected_by
 * @property Carbon|null $connected_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $checked_at
 */
final class WhatsAppConnection extends Model implements CentralModel
{
    use HasUlids;

    protected $table = 'whatsapp_connections';

    protected $guarded = [];

    protected $hidden = ['token', 'pin'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'pin' => 'encrypted', 'coexistence' => 'boolean', 'payment_method' => 'boolean',
            'connected_at' => 'datetime', 'disconnected_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function active(): bool
    {
        return $this->status === 'connected';
    }
}
