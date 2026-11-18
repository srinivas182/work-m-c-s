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
    /**
     * Read a photo with the prompt (Sprint 23.5b: a slip), through the providers that can see images. Charged like run().
     *
     * @throws AiUnavailable when no provider could answer
     */
    public function see(string $task, string $system, string $prompt, string $image, string $mime, int $maxTokens = 1000, ?string $tenantId = null): string;

    public function image(string $prompt, ?string $tenantId = null): string;

    /**
     * What a voice note says (Sprint 21.4), with the provider chosen in Admin → AI. `$seconds` is its length, for costs.
     *
     * @throws AiUnavailable
     */
    public function transcribe(string $audio, string $mime, ?float $seconds = null, ?string $tenantId = null): Transcript;
}
