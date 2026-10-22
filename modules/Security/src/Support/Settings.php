<?php

declare(strict_types=1);

namespace Wurk\Security\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Small key/value settings: platform-wide (central) and per business (shard).
 * Platform settings are cached because the admin IP check reads them on every
 * admin request; writes clear the cache.
 */
final readonly class Settings
{
    public function __construct(private TenantContext $context) {}

    public function platform(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("platform-setting:{$key}", 300, fn () => DB::table('platform_settings')->where('key', $key)->value('value'));

        return $value === null ? $default : json_decode((string) $value, true);
    }

    public function setPlatform(string $key, mixed $value, ?string $by): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_by' => $by, 'updated_at' => now()]);
        Cache::forget("platform-setting:{$key}");
    }

    public function business(string $key, mixed $default = null): mixed
    {
        $value = DB::connection($this->context->connectionName())->table('business_settings')
            ->where('tenant_id', $this->context->id())->where('key', $key)->value('value');

        return $value === null ? $default : json_decode((string) $value, true);
    }

    public function setBusiness(string $key, mixed $value): void
    {
        DB::connection($this->context->connectionName())->table('business_settings')->updateOrInsert(
            ['tenant_id' => $this->context->id(), 'key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()],
        );
    }
}
