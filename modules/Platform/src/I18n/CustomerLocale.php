<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer pages in the customer's language (Sprint 24.3): on a business's own address ({handle}.wurk.co.za), the
 * language comes from ?lang= (remembered), the cookie, then the browser. Marks the request as a customer page, so the
 * customer's language is remembered with their contact (24.2) and their messages follow it.
 */
final class CustomerLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = strtolower((string) config('wurk.domains.root'));
        $host = strtolower($request->getHost());
        if (! str_ends_with($host, '.'.$root) || in_array($host, [strtolower((string) config('wurk.domains.app')), strtolower((string) config('wurk.domains.admin'))], true)) {
            return $next($request);
        }
        app()->setLocale(Locales::forRequest($request, null));
        $request->attributes->set('wurk.customer_page', true);
        $response = $next($request);
        $lang = $request->query('lang');
        if (is_string($lang) && Locales::supported($lang)) {
            $response->headers->setCookie(cookie(Locales::COOKIE, $lang, 60 * 24 * 365, '/', null, null, false, false, 'lax'));
        }
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
