<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/** Run an AI task through WURK AI (chosen model, fallbacks, cost logging, caps). */
interface Ai
{
    /** @throws AiUnavailable when every provider failed or a cap was reached */
    public function run(string $task, string $system, string $prompt, int $maxTokens = 4000, ?string $tenantId = null): string;

    /**
     * An image's bytes (decorative use only — callers enforce where it may be placed).
     *
     * @throws AiUnavailable
     */
    public function image(string $prompt, ?string $tenantId = null): string;
}
