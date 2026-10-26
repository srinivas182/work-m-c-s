<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\ProductCatalog;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\Menus;
use Wurk\Website\Support\Pages;

/** The visual editor for one page (Sprint 4.3). */
final class EditorController
{
    public function __invoke(Request $request, string $page, Pages $pages, TenantContext $context): Response
    {
        $site = $pages->ensureSite();
        $model = Page::query()->findOrFail($page);
        $base = route('tenant.website.pages', [], false).'/'.$model->id;

        return Inertia::render('Website::Editor', [
            'page' => ['id' => $model->id, 'title' => $model->title, 'slug' => $model->slug, 'is_home' => $model->is_home],
            'revision' => $model->draft_revision,
            'content' => json_decode($model->draft, true),
            'published' => $model->published_version_id !== null,
            'canPublish' => (bool) $request->user()?->can('website.publish'),
            'businessName' => $context->current()->displayName(),
            'branding' => $site->branding,
            'menus' => app(Menus::class)->resolve($site),
            'products' => app(ProductCatalog::class)->published(),
            'urls' => [
                'pages' => route('tenant.website.pages', [], false),
                'draft' => $base.'/draft',
                'publish' => $base.'/publish',
                'versions' => $base.'/versions',
                'photos' => route('tenant.website.photos', [], false),
                'aiImage' => route('tenant.website.ai.image', [], false),
                'aiQuote' => route('tenant.website.ai.quote', [], false),
            ],
        ]);
    }
}
