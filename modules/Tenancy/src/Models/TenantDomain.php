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
 */
final class TenantDomain extends Model implements CentralModel
{
    use HasUlids;

    public const TYPE_SUBDOMAIN = 'subdomain';

    public const TYPE_CUSTOM = 'custom';

    protected $fillable = ['tenant_id', 'host', 'type', 'is_primary', 'verified_at'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $flush = function (TenantDomain $domain): void {
            $resolver = app(TenantResolver::class);
            $resolver->forgetHost($domain->host);
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
