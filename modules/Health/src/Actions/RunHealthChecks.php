<?php

declare(strict_types=1);

namespace Wurk\Health\Actions;

use Wurk\Health\Contracts\HealthCheck;

final readonly class RunHealthChecks
{
    /**
     * @param  iterable<HealthCheck>  $checks
     */
    public function __construct(private iterable $checks) {}

    /**
     * @return array{ok: bool, checks: array<string, array{ok: bool, message: string|null, duration_ms: float}>}
     */
    public function __invoke(): array
    {
        $results = [];
        $ok = true;

        foreach ($this->checks as $check) {
            $start = hrtime(true);
            $result = $check->run();

            $results[$check->name()] = [
                'ok' => $result->ok,
                'message' => $result->message,
                'duration_ms' => round((hrtime(true) - $start) / 1e6, 2),
            ];

            $ok = $ok && $result->ok;
        }

        return ['ok' => $ok, 'checks' => $results];
    }
}
