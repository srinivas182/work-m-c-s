<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/** One AI provider. Throws ProviderFailed for anything that isn't a usable answer. */
interface AiDriver
{
    public function name(): string;

    /** @return array{text: string, input_tokens: int, output_tokens: int} */
    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $apiKey): array;
}
