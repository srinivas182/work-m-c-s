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

    /** Whether this person may give WURK instructions on WhatsApp in the current business (26.1). */
    public function canInstruct(string $identityId): bool;

    /**
     * An instruction or question on WhatsApp (26.1), acting as that person. A change is only proposed: read back,
     * made after confirmFromWhatsApp().
     *
     * @return array{status: string, answer?: string, message?: string, sources?: list<array{title: string, url: string}>, action?: array{id: string, summary: string, changes: list<string>}}
     *                                                                                                                                                                                         status: answered | proposed | refused | failed
     */
    public function instruct(string $identityId, string $message, string $language): array;

    /** @return array{status: string, message: string} status: applied | refused */
    public function confirmFromWhatsApp(string $identityId, string $actionId): array;

    public function cancelFromWhatsApp(string $identityId, string $actionId): void;
}
