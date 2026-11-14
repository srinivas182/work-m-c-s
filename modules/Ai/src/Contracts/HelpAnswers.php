<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/**
 * An answer from the help centre alone (Sprint 16.3): shown before someone opens a support ticket (deflection) and
 * suggested to WURK staff as a reply. No business data, no tools, no charge to the business.
 */
interface HelpAnswers
{
    /**
     * @param  string  $for  "customer" (plain answer for the person asking) or "staff" (a draft reply WURK staff will edit)
     * @return array{answer: string|null, sources: list<array{title: string, url: string}>} answer null when the help centre doesn't cover it
     *
     * @throws AiUnavailable
     */
    public function answer(string $question, string $for = 'customer', ?string $tenantId = null): array;
}
