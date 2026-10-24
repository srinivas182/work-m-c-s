<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

/**
 * The built font files (Latin, normal style) for a site's fonts, to preload in the page head
 * so they usually arrive before first paint. Read from the built public stylesheet.
 */
final class FontPreloads
{
    /** @var array<string, list<array{family: string, url: string}>> */
    private static array $rules = [];

    /**
     * @param  list<string>  $families  e.g. ["Playfair Display Variable", "Source Sans 3 Variable"]
     * @return list<string> font URLs
     */
    public function for(array $families): array
    {
        $urls = [];
        foreach ($families as $family) {
            foreach ($this->rules() as $rule) {
                if ($rule['family'] === $family) {
                    $urls[] = $rule['url'];
                    break; // one file per family: the Latin, normal (regular weight first) one
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return list<array{family: string, url: string}> */
    private function rules(): array
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            return []; // development with the Vite server: no built files to preload
        }
        $file = (string) (json_decode((string) file_get_contents($manifest), true)['resources/css/public-site.css']['file'] ?? '');
        if ($file === '' || ! is_file(public_path('build/'.$file))) {
            return [];
        }
        if (! isset(self::$rules[$file])) {
            $css = (string) file_get_contents(public_path('build/'.$file));
            preg_match_all('/@font-face\{([^}]*)\}/', $css, $blocks);
            $found = [];
            foreach ($blocks[1] as $block) {
                if (preg_match('/font-family:\s*["\']?([^;"\']+)["\']?;/', $block, $f) !== 1
                    || preg_match('/url\(([^)]+-latin-(?:wght|400)-normal[^)]*\.woff2)\)/', $block, $u) !== 1
                    || str_contains($block, 'font-style:italic')) {
                    continue;
                }
                $found[] = ['family' => trim($f[1]), 'url' => trim($u[1], '"\'')];
            }
            self::$rules[$file] = $found;
        }

        return self::$rules[$file];
    }
}
