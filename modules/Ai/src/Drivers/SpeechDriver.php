<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/** One speech-to-text provider (Sprint 21.4). Throws ProviderFailed when it can't transcribe. */
interface SpeechDriver
{
    /**
     * @return array{text: string, language: string|null, confidence: float|null}
     *                                                                            language: ISO 639-1 where known; confidence 0–1 where the provider gives one
     */
    public function transcribe(string $model, string $audio, string $mime, string $apiKey): array;
}
