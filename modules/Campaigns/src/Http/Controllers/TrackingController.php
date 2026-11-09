<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Campaigns\Support\CampaignSender;
use Wurk\Crm\Contracts\Audiences;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** On the business's own site (Sprint 15.3a): tracked links, the open image, and opting out. */
final class TrackingController
{
    public function link(Request $request, string $handle, string $token, TenantDirectory $tenants, TenantContext $context): Response
    {
        $url = (string) $request->query('u', '');
        $home = 'https://'.$handle.'.'.config('wurk.domains.root').'/';
        $tenant = $tenants->findByHandle($handle);
        if ($tenant === null || ! hash_equals(app(CampaignSender::class)->sign($token, $url), (string) $request->query('s', '')) || ! preg_match('#^https?://#i', $url)) {
            return redirect()->away($home); // never an open redirect
        }
        $context->run($tenant, fn () => DB::connection($context->connectionName())->table('campaign_messages')->where('tenant_id', $context->id())->where('token', $token)
            ->whereNull('clicked_at')->update(['clicked_at' => now()]));

        return redirect()->away($url);
    }

    public function open(string $handle, string $token, TenantDirectory $tenants, TenantContext $context): Response
    {
        if (($tenant = $tenants->findByHandle($handle)) !== null) {
            $context->run($tenant, fn () => DB::connection($context->connectionName())->table('campaign_messages')->where('tenant_id', $context->id())->where('token', $token)
                ->whereNull('opened_at')->update(['opened_at' => now()]));
        }

        return response((string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    public function stop(string $handle, string $token, TenantDirectory $tenants, TenantContext $context): Response
    {
        $tenant = $tenants->findByHandle($handle);
        $name = $tenant?->displayName() ?? 'this business';
        if ($tenant !== null) {
            $context->run($tenant, function () use ($context, $token): void {
                $contact = DB::connection($context->connectionName())->table('campaign_messages')->where('tenant_id', $context->id())->where('token', $token)->value('contact_id');
                if ($contact !== null) {
                    app(Audiences::class)->withdrawConsent((string) $contact, 'campaign opt-out');
                }
            });
        }

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Unsubscribed</title>'
            .'<body style="font-family:system-ui,sans-serif;max-width:480px;margin:48px auto;padding:0 16px"><h1>You’re unsubscribed</h1><p>'.e($name).' won’t send you marketing messages any more.</p>', 200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
