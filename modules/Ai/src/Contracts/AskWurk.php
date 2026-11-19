<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/**
 * Ask WURK from outside the app (Sprint 25.6: the WhatsApp help line). Inside the business's context. It answers from
 * the help centre only — no figures, no changes — in the person's language, and counts toward the month's free
 * questions, with the same credits after that.
 */
interface AskWurk
{
    /**
     * @return array{status: string, answer?: string, sources?: list<array{title: string, url: string}>, credits?: float, message?: string}
     *                                                                                                                                      status: answered | quote (needs an OK to spend credits) | refused | failed
     */
    public function helpLine(string $identityId, string $question, string $language, bool $acceptCharge = false): array;

    /**
     * The person's recent help-line questions and answers, oldest first (handed to Support with "talk to a person").
     *
     * @return list<array{question: string, answer: string|null, at: string}>
     */
    public function helpLineConversation(string $identityId, int $limit = 10): array;
}
