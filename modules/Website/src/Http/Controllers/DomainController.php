<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\CustomDomains;
use Wurk\Tenancy\Contracts\CustomHostnames;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Website → Your domain (Sprint 17.3): the owner connects www.theirbusiness.co.za — one CNAME record, a certificate
 * WURK gets for them, and, once it works, it can be the site's main address. Packages with "custom domain" only.
 */
final class DomainController
{
    public function __construct(private CustomDomains $domains, private TenantContext $context, private AuditLog $audit) {}

    public function show(Entitlements $entitlements, CustomHostnames $hostnames): Response
    {
        $tenant = $this->context->current();

        return Inertia::render('Website::Domain', [
            'allowed' => (bool) ($entitlements->limits($tenant->tenantId())['custom_domain'] ?? false),
            'available' => $hostnames->configured(),
            'wurk_address' => $tenant->handle().'.'.config('wurk.domains.root'),
            'target' => (string) config('wurk.custom_domains.target'),
            'max' => (int) config('wurk.custom_domains.per_business', 2),
            'domains' => $this->domains->list(),
        ]);
    }

    public function store(Request $request, Entitlements $entitlements): RedirectResponse
    {
        abort_unless((bool) ($entitlements->limits($this->context->id())['custom_domain'] ?? false), 403);
        $host = (string) $request->validate(['host' => ['required', 'string', 'max:255']])['host'];
        $by = (string) $request->user()?->getAuthIdentifier();
        $added = $this->domains->add($host, $by);
        $this->audit->record('website.domain_added', 'domain', $added, ['by' => $by]);

        return back()->with('status', __('Added. Now add the record below where your domain is managed.'));
    }

    public function check(string $domain): RedirectResponse
    {
        $status = $this->domains->check($domain);

        return back()->with('status', match ($status) {
            'active' => __('It works! Your site is on your domain.'),
            'lapsed' => __('Your domain doesn’t point at WURK any more.'),
            default => __('Not yet — see below for what’s still needed.'),
        });
    }

    public function primary(Request $request, string $domain): RedirectResponse
    {
        $by = (string) $request->user()?->getAuthIdentifier();
        $host = $this->domains->makePrimary($domain, $by);
        $this->audit->record('website.domain_primary', 'domain', $host, ['by' => $by]);

        return back()->with('status', __(':host is now your site’s main address.', ['host' => $host]));
    }

    public function destroy(Request $request, string $domain): RedirectResponse
    {
        $by = (string) $request->user()?->getAuthIdentifier();
        $host = $this->domains->remove($domain, $by);
        $this->audit->record('website.domain_removed', 'domain', $host, ['by' => $by]);

        return back()->with('status', __('Removed. Your site is still on your WURK address.'));
    }
}
