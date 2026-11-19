<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;
use Wurk\Tenancy\Resolution\TenantResolver;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $host
 * @property string $type
 * @property bool $is_primary
 * @property Carbon|null $verified_at
 * @property string $status
 * @property string|null $provider_id
 * @property string|null $ssl_status
 * @property string|null $problem
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 */
final class TenantDomain extends Model implements CentralModel
{
    use HasUlids;

    public const TYPE_SUBDOMAIN = 'subdomain';

    public const TYPE_CUSTOM = 'custom';

    /** Sprint 17.3: a custom domain's state. */
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const LAPSED = 'lapsed';

    protected $fillable = ['tenant_id', 'host', 'type', 'is_primary', 'verified_at', 'status', 'provider_id', 'ssl_status', 'problem', 'checked_at'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $flush = function (TenantDomain $domain): void {
            $resolver = app(TenantResolver::class);
            $resolver->forgetHost($domain->host);
            $resolver->forgetPrimary($domain->tenant_id);
            $original = $domain->getOriginal('host');
            if (is_string($original) && $original !== $domain->host) {
                $resolver->forgetHost($original);
            }
        };

        self::saved($flush);
        self::deleted($flush);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
