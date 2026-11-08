<?php

declare(strict_types=1);

namespace Wurk\Referrals\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Support\Attribution;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** {business}/r/CODE: remember who referred the visitor (first-party cookie), then go to the site (or a page on it). */
final class ReferralLinkController
{
    public function __invoke(Request $request, string $handle, string $code, TenantDirectory $tenants, TenantContext $context): RedirectResponse
    {
        $tenant = $tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive(), 404);
        $to = (string) $request->query('to', '/');
        $to = preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $to) === 1 ? $to : '/'; // only a path on this same site
        $cookie = $context->run($tenant, function () use ($code): ?Cookie {
            $rules = app(ReferralRules::class)->rules();
            $member = app(Attribution::class)->byCode($code);

            return $rules['enabled'] && $member !== null ? app(Attribution::class)->cookie($member->code, $rules['link_days']) : null;
        });
        $response = redirect($to);

        return $cookie === null ? $response : $response->withCookie($cookie);
    }
}
