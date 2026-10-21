<?php

declare(strict_types=1);

namespace Wurk\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * Index of which businesses a person belongs to (for sign-in and My WURK).
 * Holds no business data.
 *
 * @property string $id
 * @property string $identity_id
 * @property string $tenant_id
 * @property string $kind staff|customer|agent
 * @property string $status
 */
final class Membership extends Model implements CentralModel
{
    use HasUlids;

    public const KIND_STAFF = 'staff';

    public const KIND_CUSTOMER = 'customer';

    public const KIND_AGENT = 'agent';

    protected $fillable = ['identity_id', 'tenant_id', 'kind', 'status'];

    /** @return BelongsTo<Identity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }
}
