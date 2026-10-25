<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\Vite;

/**
 * Asset links for published pages, resolved from the current build on every request and written
 * as same-site paths (/build/…): styles and fonts load from the business's own address, and a deploy
 * never leaves a page pointing at assets that no longer exist.
 */
final class PublicAssets
{
    public function stylesheet(): string
    {
        return $this->path('resources/css/public-site.css');
    }

    public function islands(): string
    {
        return $this->path('resources/js/site-islands.ts');
    }

    private function path(string $entry): string
    {
        $url = Vite::asset($entry);

        return Vite::isRunningHot() ? $url : (string) (parse_url($url, PHP_URL_PATH) ?: $url);
    }
}
