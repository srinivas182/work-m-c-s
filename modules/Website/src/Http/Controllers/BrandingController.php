<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Support\Branding;
use Wurk\Website\Support\Pages;

/** Branding inputs; the screen arrives in 4.4. */
final class BrandingController
{
    public function show(Request $request, Pages $pages, TenantContext $context): JsonResponse|InertiaResponse
    {
        $payload = ['branding' => $pages->ensureSite()->branding, 'font_pairings' => config('website.font_pairings'), 'corner_styles' => config('website.corner_styles')];
        if ($request->wantsJson()) {
            return new JsonResponse($payload);
        }

        return Inertia::render('Website::Branding', [...$payload, 'businessName' => $context->current()->displayName(), 'photosUrl' => route('tenant.website.photos', [], false), 'base' => route('tenant.website.branding', [], false)]);
    }

    public function update(Request $request, Pages $pages, Branding $branding, AuditLog $audit): JsonResponse|RedirectResponse
    {
        $site = $pages->ensureSite();
        $clean = $branding->validate($request->all());
        $site->update(['branding' => [...$site->branding, ...$clean]]);
        $pages->rerenderPublished(); // the live site picks up the new look
        $audit->record('website.branding_changed', 'site', $site->tenant_id, $clean);

        return $request->wantsJson() ? new JsonResponse(['branding' => $site->branding]) : back()->with('status', 'Branding saved.');
    }
}
