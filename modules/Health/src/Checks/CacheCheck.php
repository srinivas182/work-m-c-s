<?php

declare(strict_types=1);

namespace Wurk\Health\Checks;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Health\Contracts\CheckResult;
use Wurk\Health\Contracts\HealthCheck;

final readonly class CacheCheck implements HealthCheck
{
    public function __construct(private Repository $cache) {}

    public function name(): string
    {
        return 'cache';
    }

    public function run(): CheckResult
    {
        try {
            $key = 'health:'.Str::random(12);
            $this->cache->put($key, 'ok', 10);
            $value = $this->cache->pull($key);

            return $value === 'ok' ? CheckResult::ok() : CheckResult::failed('Cache read-back mismatch');
        } catch (Throwable) {
            return CheckResult::failed('Cache unreachable');
        }
    }
}
