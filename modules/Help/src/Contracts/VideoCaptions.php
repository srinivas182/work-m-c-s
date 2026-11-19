<?php

declare(strict_types=1);

namespace Wurk\Help\Contracts;

/**
 * Subtitles for the help centre's videos (Sprint 25.6). English is written with the video (Admin → Help centre);
 * other languages keep the English timings, are drafted by WURK AI and shown once a language reviewer approves them.
 */
interface VideoCaptions
{
    /**
     * The videos with English subtitles, and where this language stands for each.
     *
     * @return list<array{article_id: string, title: string, english: list<string>, text: list<string>|null, status: string}> status: missing | draft | approved
     */
    public function forLocale(string $locale): array;

    /**
     * Saves one language's subtitles as the English cues' texts, line for line (the timings stay the English ones).
     *
     * @param  list<string>  $texts
     * @return bool false when the lines don't match the English cues
     */
    public function save(string $articleId, string $locale, array $texts, bool $approved, ?string $by, bool $byAi = false): bool;
}
