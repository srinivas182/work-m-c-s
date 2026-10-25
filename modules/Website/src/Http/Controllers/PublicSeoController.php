<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\SiteFacts;
use Wurk\Website\Support\SiteSeo;

/** robots.txt, sitemap.xml and llms.txt for each business's site — from what the owner published. */
final class PublicSeoController
{
    private const TYPES = ['robots' => 'text/plain', 'sitemap' => 'application/xml', 'llms' => 'text/plain'];

    public function __invoke(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context, SiteSeo $seo): Response
    {
        $type = (string) $request->route('type');
        $tenant = $tenants->findByHandle($handle);
        $base = $request->getSchemeAndHttpHost();
        $live = $tenant !== null && $tenant->isActive()
            && $context->run($tenant, fn () => Page::query()->where('is_home', true)->whereNotNull('published_version_id')->exists());

        if ($type === 'robots') {
            return $this->text($seo->robots($base, $live), 'robots');
        }
        if (! $live || $tenant === null) {
            abort(404);
        }
        $facts = $context->run($tenant, fn () => app(SiteFacts::class)->collect());

        return $type === 'sitemap' ? $this->text($seo->sitemap($facts, $base), 'sitemap') : $this->text($seo->llms($facts, $base), 'llms');
    }

    private function text(string $body, string $type): Response
    {
        return new Response($body, 200, ['Content-Type' => self::TYPES[$type].'; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
