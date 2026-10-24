<?php

declare(strict_types=1);

use Wurk\Website\Support\FontPreloads;

it('preloads only the Latin, normal-style file of each of the site’s fonts', function () {
    $public = sys_get_temp_dir().'/wurk-public-'.uniqid();
    mkdir("{$public}/build/assets", 0777, true);
    file_put_contents("{$public}/build/manifest.json", json_encode(['resources/css/public-site.css' => ['file' => 'assets/public-site-test.css']]));
    // As Vite minifies it: unquoted families, several subsets and styles per family.
    file_put_contents("{$public}/build/assets/public-site-test.css", implode('', [
        '@font-face{font-family:Playfair Display Variable;font-style:normal;src:url(/build/assets/playfair-display-cyrillic-wght-normal-A.woff2)format("woff2-variations");unicode-range:U+400-45F}',
        '@font-face{font-family:Playfair Display Variable;font-style:normal;src:url(/build/assets/playfair-display-latin-ext-wght-normal-B.woff2)format("woff2-variations")}',
        '@font-face{font-family:Playfair Display Variable;font-style:italic;src:url(/build/assets/playfair-display-latin-wght-italic-C.woff2)format("woff2-variations")}',
        '@font-face{font-family:Playfair Display Variable;font-style:normal;src:url(/build/assets/playfair-display-latin-wght-normal-D.woff2)format("woff2-variations");unicode-range:U+0-FF}',
        '@font-face{font-family:Lato;font-style:normal;font-weight:400;src:url(/build/assets/lato-latin-400-normal-E.woff2)format("woff2")}',
        '@font-face{font-family:Lato;font-style:normal;font-weight:700;src:url(/build/assets/lato-latin-700-normal-F.woff2)format("woff2")}',
    ]));
    app()->usePublicPath($public);

    expect(app(FontPreloads::class)->for(['Playfair Display Variable', 'Lato']))
        ->toBe(['/build/assets/playfair-display-latin-wght-normal-D.woff2', '/build/assets/lato-latin-400-normal-E.woff2']);
});
