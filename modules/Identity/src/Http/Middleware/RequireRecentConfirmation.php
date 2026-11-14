<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Http\Controllers\ConfirmController;

/**
 * "Confirm it's you" (Sprint 18.2b, ASVS 3.7): before something that would hurt if a borrowed or stolen session did
 * it (downloading all of a business's data), the person confirms with their password or a fresh code — unless they
 * signed in or confirmed in the last 15 minutes. Alias: `confirmed`.
 */
final class RequireRecentConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ConfirmController::recent($request)) {
            return $next($request);
        }
        $to = $request->isMethod('GET') ? $request->getRequestUri() : (string) parse_url((string) url()->previous(), PHP_URL_PATH);

        return redirect()->route('identity.confirm', ['to' => $to])
            ->with('warning', $request->isMethod('GET') ? null : 'Please confirm it’s you, then try again.');
    }
}
