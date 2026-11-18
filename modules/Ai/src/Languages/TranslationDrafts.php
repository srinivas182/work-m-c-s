<?php

declare(strict_types=1);

namespace Wurk\Ai\Languages;

use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Ai\Contracts\Ai;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\I18n\Locales;
use Wurk\Platform\I18n\TranslationStore;

/**
 * First drafts of WURK's texts in a language, by WURK AI (Sprint 24.2, decision A), using the language's glossary so
 * the same thing is always called the same. Drafts are marked as such; people review them in Admin → Languages.
 * Placeholders (:name) must come back unchanged, or the draft is dropped.
 */
final readonly class TranslationDrafts
{
    public const BATCH = 60;

    public function __construct(private Ai $ai, private AuditLog $audit) {}

    /**
     * The texts in this language with no translation yet.
     *
     * @return list<string>
     */
    public function missing(string $locale): array
    {
        $have = Locales::messages($locale);

        return array_values(array_filter(TranslationStore::sourceTexts(), fn (string $s) => ! isset($have[$s])));
    }

    /** Draft up to $limit missing texts; returns how many were drafted. */
    public function draft(string $locale, int $limit = 600): int
    {
        if (! Locales::supported($locale) || $locale === 'en') {
            return 0;
        }
        $missing = array_slice($this->missing($locale), 0, $limit);
        $glossary = DB::table('translation_glossary')->where('locale', $locale)->orderBy('term')->get(['term', 'translation'])
            ->map(fn ($g) => "{$g->term} = {$g->translation}")->implode("\n");
        $language = Locales::SUPPORTED[$locale][0];
        $done = 0;
        foreach (array_chunk($missing, self::BATCH) as $batch) {
            $system = "You translate the fixed texts of WURK, software for small businesses in Africa (bookings, online shop, payments, invoices), from English into {$language}. "
                .'Write natural, everyday '.$language.' a small-business owner on a phone understands; keep it short, like the English. Keep every :placeholder exactly as it is, '
                .'and keep R amounts, WURK and brand names (WhatsApp, Paystack, Google) unchanged. Reply with JSON only: an object mapping each English text to its translation.'
                .($glossary !== '' ? "\nAlways use these words:\n{$glossary}" : '');
            try {
                $raw = $this->ai->run('rewrite', $system, (string) json_encode($batch, JSON_UNESCAPED_UNICODE), 8000);
            } catch (Throwable) {
                break; // WURK AI is busy: the rest waits for the next run
            }
            $answer = (array) json_decode((string) preg_replace('/^[^{]*|[^}]*$/s', '', $raw), true);
            foreach ($batch as $source) {
                if (is_string($answer[$source] ?? null) && TranslationStore::save($locale, $source, (string) $answer[$source], 'draft', null)) {
                    $done++;
                }
            }
        }
        $this->audit->record('languages.drafted', 'translations', $locale, ['drafted' => $done], ['platform' => true]);

        return $done;
    }
}
