<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * WURK's languages (Sprint 24). English is the source: every fixed text is written in English in the code, and each
 * other language is a JSON file of translations (lang/{code}.json), drafted by WURK AI and reviewed by people. A text
 * with no translation shows in English, so nothing is ever blank.
 */
final class Locales
{
    /** code => [English name, own name] */
    public const SUPPORTED = [
        'en' => ['English', 'English'],
        'zu' => ['isiZulu', 'isiZulu'],
        'xh' => ['isiXhosa', 'isiXhosa'],
        'af' => ['Afrikaans', 'Afrikaans'],
        'st' => ['Sesotho', 'Sesotho'],
        'sw' => ['Swahili', 'Kiswahili'],
        'fr' => ['French', 'Français'],
        'pt' => ['Portuguese', 'Português'],
        'ha' => ['Hausa', 'Hausa'],
        'yo' => ['Yoruba', 'Yorùbá'],
    ];

    public const COOKIE = 'wurk_lang';

    public static function supported(string $code): bool
    {
        return isset(self::SUPPORTED[$code]);
    }

    /**
     * The language for a request: the signed-in person's choice, else ?lang= or the cookie, else the browser's
     * Accept-Language among WURK's languages, else English.
     */
    public static function forRequest(Request $request, ?string $personal): string
    {
        foreach ([$request->query('lang'), $personal, $request->cookie(self::COOKIE)] as $candidate) {
            if (is_string($candidate) && self::supported($candidate)) {
                return $candidate;
            }
        }
        foreach ($request->getLanguages() as $accept) {
            $code = strtolower(substr($accept, 0, 2));
            if (self::supported($code)) {
                return $code;
            }
        }

        return 'en';
    }

    /**
     * The translations for one language (empty for English), keyed by the English text.
     *
     * @return array<string, string>
     */
    public static function messages(string $code): array
    {
        if ($code === 'en') {
            return [];
        }
        $file = lang_path($code.'.json');

        return File::exists($file) ? (array) json_decode((string) File::get($file), true) : [];
    }

    /** A short fingerprint of a language's translations, so the browser can cache them until they change. */
    public static function version(string $code): string
    {
        $file = lang_path($code.'.json');

        return File::exists($file) ? substr(md5((string) filemtime($file).filesize($file)), 0, 10) : '0';
    }

    /**
     * Rand in the language's own style: R1 380.00 in South Africa's languages and English; 1 380,00 R in French and
     * Portuguese. The same rule as the browser's (resources/js/i18n.ts).
     */
    public static function money(int $cents, ?string $code = null): string
    {
        $code ??= app()->getLocale();
        $negative = $cents < 0 ? '-' : '';
        $comma = in_array($code, ['fr', 'pt'], true);
        $n = number_format(abs($cents) / 100, 2, $comma ? ',' : '.', ' ');

        return $comma ? $negative.$n.' R' : $negative.'R'.$n;
    }
}
