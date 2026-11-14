<?php

declare(strict_types=1);

namespace Wurk\Help\Contracts;

/** The help centre, for answering a question in plain words (Sprint 16.2 "Ask WURK", 16.3 ticket deflection). */
interface HelpSearch
{
    /**
     * The published articles that best match a question, best first, with their text (Markdown) to answer from.
     *
     * @return list<array{slug: string, title: string, summary: string|null, url: string, text: string}>
     */
    public function relevant(string $question, int $limit = 3): array;

    /** Count a how-to question nothing answered (by its words only, as unanswered searches are), so WURK writes it next. */
    public function missed(string $question): void;
}
