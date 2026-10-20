<?php

declare(strict_types=1);

namespace Wurk\Health\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
