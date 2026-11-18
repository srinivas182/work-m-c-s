<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Illuminate\Http\Response;

/**
 * A language's translations as a small script (Sprint 24.1), loaded before the app so pages render in the right
 * language from the first paint. The URL carries the version, so browsers keep it until the translations change.
 */
final class TranslationsController
{
    public function __invoke(string $code): Response
    {
        abort_unless(Locales::supported($code) && $code !== 'en', 404);

        return new Response('window.__wurkI18n='.json_encode((object) Locales::messages($code), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP).';', 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
