<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/** Development and tests: returns the next scripted transcript (or a fixed one). */
final class SimulatedSpeechDriver implements SpeechDriver
{
    /** @var list<array{text: string, language: string|null, confidence: float|null}|'fail'> */
    public static array $script = [];

    /** @var list<array{model: string, bytes: int, mime: string}> */
    public static array $heard = [];

    public function transcribe(string $model, string $audio, string $mime, string $apiKey): array
    {
        self::$heard[] = ['model' => $model, 'bytes' => strlen($audio), 'mime' => $mime];
        $next = array_shift(self::$script) ?? ['text' => 'Hello, can I book for Saturday?', 'language' => 'en', 'confidence' => 0.95];
        if ($next === 'fail') {
            throw new ProviderFailed('Simulated failure');
        }

        return $next;
    }
}
