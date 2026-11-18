<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Translations people and WURK AI have added (Sprint 24.2), over the shipped lang/{code}.json files. Read on every
 * request in a language, so they're cached per language and the cache key moves whenever one changes.
 */
final class TranslationStore
{
    /** A language counts as reviewed (no longer "preview") once this share of its texts has been reviewed. */
    public const REVIEWED_SHARE = 0.95;

    /** @return array<string, string> English text => translation */
    public static function overrides(string $locale): array
    {
        if ($locale === 'en') {
            return [];
        }
        try {
            return Cache::rememberForever('translations:'.$locale.':'.self::stamp($locale), fn () => DB::table('translations')->where('locale', $locale)
                ->pluck('text', 'source')->map(fn ($t) => (string) $t)->all());
        } catch (Throwable) {
            return []; // before migrations (installs, some tests): the files alone
        }
    }

    /** Changes whenever a translation in this language changes. */
    public static function stamp(string $locale): string
    {
        try {
            return (string) Cache::rememberForever('translations-stamp:'.$locale, function () use ($locale): string {
                $r = DB::table('translations')->where('locale', $locale)->selectRaw('max(updated_at) as at, count(*) as n')->first();

                return substr(md5(($r->at ?? '0').'|'.($r->n ?? 0)), 0, 12);
            });
        } catch (Throwable) {
            return '0';
        }
    }

    /** Save one text (a draft from WURK AI, or a person's correction). Placeholders must survive. */
    public static function save(string $locale, string $source, string $text, string $status, ?string $by): bool
    {
        if (! Locales::supported($locale) || $locale === 'en' || trim($text) === '' || ! self::samePlaceholders($source, $text)) {
            return false;
        }
        $hash = sha1($source);
        $now = now();
        $existing = DB::table('translations')->where('locale', $locale)->where('source_hash', $hash)->first();
        if ($existing !== null && $existing->status === 'reviewed' && $status === 'draft') {
            return false; // a draft never replaces a person's review
        }
        if ($existing === null) {
            DB::table('translations')->insert(['id' => (string) Str::ulid(), 'locale' => $locale, 'source_hash' => $hash, 'source' => $source, 'text' => trim($text),
                'status' => $status, 'updated_by' => $by, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('translations')->where('id', $existing->id)->update(['text' => trim($text), 'status' => $status, 'updated_by' => $by, 'updated_at' => $now]);
        }
        Cache::forever('translations-stamp:'.$locale, Str::random(12)); // a new stamp: every server and browser picks the change up

        return true;
    }

    /** Every :placeholder in the English text, and no others, appears in the translation. */
    public static function samePlaceholders(string $source, string $text): bool
    {
        preg_match_all('/:([a-z_]+)|\{([a-z_]+)\}/i', $source, $a); // Laravel's :name and the message templates' {name}
        preg_match_all('/:([a-z_]+)|\{([a-z_]+)\}/i', $text, $b);
        $a[1] = $a[0];
        $b[1] = $b[0];
        $x = array_unique($a[1]);
        $y = array_unique($b[1]);
        sort($x);
        sort($y);

        return $x === $y;
    }

    /**
     * How far each language has come, against the texts in lang/_source.json.
     *
     * @return array<string, array{total: int, translated: int, reviewed: int, enabled: bool, preview: bool}>
     */
    public static function progress(): array
    {
        $source = self::sourceTexts();
        $total = count($source);
        $set = array_flip($source);
        $enabled = self::enabledMap();
        $out = [];
        foreach (array_keys(Locales::SUPPORTED) as $code) {
            if ($code === 'en') {
                continue;
            }
            $rows = DB::table('translations')->where('locale', $code)->get(['source', 'status']);
            $files = array_intersect_key(Locales::fileMessages($code), $set);
            $reviewed = $rows->filter(fn ($r) => $r->status === 'reviewed' && isset($set[$r->source]))->count();
            $translated = count(array_unique([...array_keys($files), ...$rows->pluck('source')->filter(fn ($s) => isset($set[$s]))->all()]));
            $out[$code] = ['total' => $total, 'translated' => $translated, 'reviewed' => $reviewed, 'enabled' => $enabled[$code] ?? true,
                'preview' => $total === 0 || $reviewed < $total * self::REVIEWED_SHARE];
        }

        return $out;
    }

    /** @return list<string> the English texts to translate */
    public static function sourceTexts(): array
    {
        $file = lang_path('_source.json');

        return is_file($file) ? array_values(array_map('strval', (array) json_decode((string) file_get_contents($file), true))) : [];
    }

    /** How many texts there are to translate (read once per process). */
    public static function sourceCount(): int
    {
        static $counts = [];
        $file = lang_path('_source.json');
        $key = is_file($file) ? filemtime($file).':'.filesize($file) : 'none';

        return $counts[$key] ??= count(self::sourceTexts());
    }

    /** @return array<string, bool> */
    public static function enabledMap(): array
    {
        try {
            return DB::table('translation_languages')->pluck('enabled', 'locale')->map(fn ($v) => (bool) $v)->all();
        } catch (Throwable) {
            return [];
        }
    }
}
