<?php

declare(strict_types=1);

namespace Wurk\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Security\Support\Settings;

/**
 * When WURK sets an allow-list, the admin host (sign-in included) only answers
 * those addresses. Off by default. WURK_ADMIN_IP_ALLOWLIST=false in the
 * environment switches it off in an emergency (e.g. the office IP changed).
 */
final readonly class AdminIpAllowList
{
    public function __construct(private Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (strcasecmp($request->getHost(), (string) config('wurk.domains.admin')) !== 0 || ! config('wurk.security.admin_ip_allowlist', true)) {
            return $next($request);
        }

        /** @var list<string> $allowed */
        $allowed = (array) $this->settings->platform('admin_ip_allowlist', []);
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(403, 'The WURK admin is not available from this network.');
        }

        return $next($request);
    }
}
