<?php

declare(strict_types=1);

namespace Wurk\Health\Checks;

use Illuminate\Database\DatabaseManager;
use Throwable;
use Wurk\Health\Contracts\CheckResult;
use Wurk\Health\Contracts\HealthCheck;

final readonly class DatabaseCheck implements HealthCheck
{
    public function __construct(private DatabaseManager $db) {}

    public function name(): string
    {
        return 'database';
    }

    public function run(): CheckResult
    {
        try {
            $this->db->connection()->select('select 1');

            return CheckResult::ok();
        } catch (Throwable) {
            // Never leak connection details from a public endpoint.
            return CheckResult::failed('Database unreachable');
        }
    }
}
