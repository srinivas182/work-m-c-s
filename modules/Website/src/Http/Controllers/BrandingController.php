<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Website\Support\Branding;
use Wurk\Website\Support\Pages;

/** Branding inputs; the screen arrives in 4.4. */
final class BrandingController
{
    public function show(Pages $pages): JsonResponse
    {
        return new JsonResponse(['branding' => $pages->ensureSite()->branding, 'font_pairings' => config('website.font_pairings'), 'corner_styles' => config('website.corner_styles')]);
    }

    public function update(Request $request, Pages $pages, Branding $branding, AuditLog $audit): JsonResponse
    {
        $site = $pages->ensureSite();
        $clean = $branding->validate($request->all());
        $site->update(['branding' => [...$site->branding, ...$clean]]);
        $audit->record('website.branding_changed', 'site', $site->tenant_id, $clean);

        return new JsonResponse(['branding' => $site->branding]);
    }
}
