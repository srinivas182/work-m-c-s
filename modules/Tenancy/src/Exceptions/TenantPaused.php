<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The business is being moved or restored (Sprint 18.4a): pages answer 503 for those few minutes, then carry on. */
final class TenantPaused extends HttpException
{
    public function __construct()
    {
        parent::__construct(503, 'This business is being moved to a new server — back in a few minutes.', null, ['Retry-After' => '120']);
    }

    /** Say what is happening, not a bare "Service Unavailable". */
    public function render(Request $request): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => $this->getMessage()], 503, $this->getHeaders())
            : response($this->getMessage(), 503, [...$this->getHeaders(), 'Content-Type' => 'text/plain; charset=utf-8']);
    }
}
