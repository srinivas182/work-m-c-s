<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Wurk\Platform\Money\Money;

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

        return [...self::fileMessages($code), ...TranslationStore::overrides($code)]; // people's reviews and AI drafts over the shipped file
    }

    /** @return array<string, string> the shipped translations (lang/{code}.json) */
    public static function fileMessages(string $code): array
    {
        $file = lang_path($code.'.json');

        return File::exists($file) ? (array) json_decode((string) File::get($file), true) : [];
    }

    /** A short fingerprint of a language's translations, so the browser can cache them until they change. */
    public static function version(string $code): string
    {
        $file = lang_path($code.'.json');

        return substr(md5((File::exists($file) ? filemtime($file).filesize($file) : '0').'|'.TranslationStore::stamp($code)), 0, 10);
    }

    /**
     * The languages people can choose, with "preview" for those still being reviewed.
     *
     * @return list<array{code: string, name: string, preview: bool}>
     */
    public static function offered(): array
    {
        // On every page: two small queries (cached briefly), never the whole progress report.
        try {
            [$reviewed, $enabled] = Cache::remember('i18n-offered:'.implode(',', array_map(fn ($c) => TranslationStore::stamp($c), array_keys(self::SUPPORTED))), 600, fn () => [
                DB::table('translations')->where('status', 'reviewed')->groupBy('locale')->selectRaw('locale, count(*) as n')->pluck('n', 'locale')->map(fn ($n) => (int) $n)->all(),
                TranslationStore::enabledMap(),
            ]);
        } catch (\Throwable) {
            [$reviewed, $enabled] = [[], []];
        }
        $total = TranslationStore::sourceCount();
        $out = [['code' => 'en', 'name' => 'English', 'preview' => false]];
        foreach (self::SUPPORTED as $code => $names) {
            if ($code === 'en' || ! ($enabled[$code] ?? true)) {
                continue;
            }
            $out[] = ['code' => $code, 'name' => $names[1], 'preview' => $total === 0 || ($reviewed[$code] ?? 0) < $total * TranslationStore::REVIEWED_SHARE];
        }

        return $out;
    }

    /** The business's money in the reader's language (25.1b: in the business's currency). See {@see Money}. */
    public static function money(int $cents, ?string $code = null): string
    {
        return Money::format($cents, $code);
    }
}
