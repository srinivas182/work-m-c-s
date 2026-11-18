<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sets the language for the request (Sprint 24.1), and remembers a ?lang= choice in a cookie for visitors. */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $personal = $user !== null && isset($user->locale) && is_string($user->locale) ? $user->locale : null;
        $code = Locales::forRequest($request, $personal);
        app()->setLocale($code);
        $response = $next($request);
        if (is_string($request->query('lang')) && Locales::supported((string) $request->query('lang'))) {
            $response->headers->setCookie(cookie(Locales::COOKIE, (string) $request->query('lang'), 60 * 24 * 365, null, null, null, false));
        }

        return $response;
    }
}
