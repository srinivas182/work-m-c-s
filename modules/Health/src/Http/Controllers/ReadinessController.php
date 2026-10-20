<?php

declare(strict_types=1);

namespace Wurk\Health\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Wurk\Health\Actions\RunHealthChecks;

final class ReadinessController
{
    public function __invoke(RunHealthChecks $runChecks): JsonResponse
    {
        $report = $runChecks();

        return new JsonResponse(
            ['status' => $report['ok'] ? 'ok' : 'degraded', 'checks' => $report['checks']],
            $report['ok'] ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
