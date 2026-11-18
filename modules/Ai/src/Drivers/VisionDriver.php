<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/** A provider that can read a photo with the prompt (Sprint 23.5b: a slip). Throws ProviderFailed for anything unusable. */
interface VisionDriver
{
    /** @return array{text: string, input_tokens: int, output_tokens: int} */
    public function see(string $model, string $system, string $prompt, string $image, string $mime, int $maxTokens, string $apiKey): array;
}
