<?php

declare(strict_types=1);

namespace Wurk\Ai\Languages;

use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Ai\Contracts\Ai;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Help\Contracts\VideoCaptions;
use Wurk\Platform\I18n\Locales;

/**
 * First drafts of the help videos' subtitles in a language (Sprint 25.6), by WURK AI with the language's glossary.
 * Only the words are translated, line for line; the timings stay the English ones. A language reviewer approves them
 * in Admin → Languages before they're shown.
 */
final readonly class CaptionDrafts
{
    public function __construct(private Ai $ai, private VideoCaptions $captions, private AuditLog $audit) {}

    /** Drafts every video still missing this language; returns how many. */
    public function draft(string $locale): int
    {
        if (! Locales::supported($locale) || $locale === 'en') {
            return 0;
        }
        $language = Locales::SUPPORTED[$locale][0];
        $glossary = DB::table('translation_glossary')->where('locale', $locale)->orderBy('term')->get(['term', 'translation'])
            ->map(fn ($g) => "{$g->term} = {$g->translation}")->implode("\n");
        $system = "You translate the subtitles of a 30-second help video for WURK, software for small businesses in Africa, from English into {$language}. "
            .'Each item is one subtitle line on screen: keep it as short as the English, in everyday words. Keep the names of WURK’s screens and buttons, '
            .'WURK and brand names (WhatsApp, Paystack, Google) as they are. Reply with JSON only: an array of the translated lines, in the same order and the same number.'
            .($glossary !== '' ? "\nAlways use these words:\n{$glossary}" : '');
        $done = 0;
        foreach ($this->captions->forLocale($locale) as $video) {
            if ($video['status'] !== 'missing') {
                continue;
            }
            try {
                $raw = $this->ai->run('rewrite', $system, (string) json_encode($video['english'], JSON_UNESCAPED_UNICODE), 3000);
            } catch (Throwable) {
                break; // WURK AI is busy: the rest waits for the next run
            }
            $lines = json_decode((string) preg_replace('/^[^\[]*|[^\]]*$/s', '', $raw), true);
            if (is_array($lines) && array_is_list($lines) && $this->captions->save($video['article_id'], $locale, array_map('strval', $lines), false, null, true)) {
                $done++;
            }
        }
        $this->audit->record('languages.captions_drafted', 'help_video_captions', $locale, ['drafted' => $done], ['platform' => true]);

        return $done;
    }
}
