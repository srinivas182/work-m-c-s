<?php

declare(strict_types=1);

namespace Wurk\Health\Contracts;

/**
 * A readiness check. Any module can contribute one by tagging its implementation
 * with HealthCheck::TAG in its service provider.
 */
interface HealthCheck
{
    public const TAG = 'wurk.health.checks';

    /** Short machine name, e.g. "database". */
    public function name(): string;

    /** Must not throw; return a failed result instead. */
    public function run(): CheckResult;
}
