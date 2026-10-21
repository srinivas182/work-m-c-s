<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Models\Identity;

final class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->user();
        abort_unless($identity instanceof Identity && $identity->isPlatformStaff() && $identity->hasMfa(), 403);

        return $next($request);
    }
}
