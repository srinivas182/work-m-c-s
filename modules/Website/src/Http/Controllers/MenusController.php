<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\Menus;
use Wurk\Website\Support\Pages;

/** Owners: Website → Menus. */
final class MenusController
{
    public function show(Pages $pages): Response
    {
        $site = $pages->ensureSite();

        return Inertia::render('Website::Menus', [
            'menus' => ['header' => $site->menus['header'] ?? [], 'footer' => $site->menus['footer'] ?? []],
            'pages' => Page::query()->orderBy('position')->get(['id', 'title', 'slug', 'is_home'])->toArray(),
            'limits' => Menus::LIMITS,
            'base' => route('tenant.website.menus', [], false),
        ]);
    }

    public function update(Request $request, Pages $pages, Menus $menus, AuditLog $audit): RedirectResponse
    {
        $site = $pages->ensureSite();
        $clean = $menus->validate($request->all());
        $site->update(['menus' => [...$site->menus, ...$clean]]);
        $pages->rerenderPublished(); // the live site picks up the new menus
        $audit->record('website.menus_changed', 'site', $site->tenant_id, ['header' => count($clean['header']), 'footer' => count($clean['footer'])]);

        return back()->with('status', 'Menus saved.');
    }
}
