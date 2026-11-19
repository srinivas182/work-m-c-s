<?php

declare(strict_types=1);

namespace Wurk\Trust\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A business's verification (25.4): its identity and registry checks, decided by WURK HQ.
 *
 * @property string $tenant_id
 * @property string $identity_status
 * @property Carbon|null $identity_submitted_at
 * @property Carbon|null $identity_decided_at
 * @property Carbon|null $identity_expires_at
 * @property string|null $identity_reason
 * @property string|null $registry_number
 * @property string $registry_status
 * @property Carbon|null $registry_submitted_at
 * @property Carbon|null $registry_decided_at
 * @property string|null $registry_reason
 * @property string|null $decided_by
 * @property list<array{kind: string, path: string, mime: string}>|null $documents
 * @property Carbon|null $documents_delete_after
 */
final class BusinessVerification extends Model implements CentralModel
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['identity_status' => 'none', 'registry_status' => 'none'];

    protected function casts(): array
    {
        return ['identity_submitted_at' => 'datetime', 'identity_decided_at' => 'datetime', 'identity_expires_at' => 'datetime', 'registry_submitted_at' => 'datetime',
            'registry_decided_at' => 'datetime', 'documents' => 'array', 'documents_delete_after' => 'datetime'];
    }
}
