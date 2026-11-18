<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI's transcription API (POST /v1/audio/transcriptions). The gpt-4o transcribe models return token log
 * probabilities, from which WURK estimates how sure the transcript is; whisper-1 returns the detected language.
 */
final class OpenAiSpeechDriver implements SpeechDriver
{
    public function transcribe(string $model, string $audio, string $mime, string $apiKey): array
    {
        $whisper = str_starts_with($model, 'whisper');
        $ext = ['audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'm4a', 'audio/amr' => 'amr', 'audio/wav' => 'wav', 'audio/webm' => 'webm'][strtok($mime, ';') ?: ''] ?? 'ogg';
        $fields = ['model' => $model, 'response_format' => $whisper ? 'verbose_json' : 'json'] + ($whisper ? [] : ['include[]' => 'logprobs']);
        try {
            $r = Http::timeout(90)->acceptJson()->withToken($apiKey)->attach('file', $audio, 'voice-note.'.$ext, ['Content-Type' => $mime])
                ->post('https://api.openai.com/v1/audio/transcriptions', $fields);
        } catch (ConnectionException) {
            throw new ProviderFailed('OpenAI unreachable');
        }
        if (! $r->successful() || ! is_string($r->json('text'))) {
            throw new ProviderFailed('OpenAI: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $confidence = null;
        $logprobs = array_filter(array_map(fn ($t) => is_array($t) ? ($t['logprob'] ?? null) : null, (array) $r->json('logprobs', [])), 'is_numeric');
        if ($logprobs !== []) {
            $confidence = exp(array_sum($logprobs) / count($logprobs)); // the geometric mean of the tokens' probabilities
        } elseif ($whisper && ($segments = (array) $r->json('segments', [])) !== []) {
            $confidence = exp(array_sum(array_map(fn ($s) => (float) ($s['avg_logprob'] ?? 0), $segments)) / count($segments));
        }
        $language = $r->json('language');

        return ['text' => trim((string) $r->json('text')), 'language' => is_string($language) ? self::iso($language) : null, 'confidence' => $confidence];
    }

    /** whisper-1 names the language ("zulu"); WURK keeps ISO 639-1 codes. */
    private static function iso(string $language): ?string
    {
        $language = strtolower($language);

        return strlen($language) === 2 ? $language : ([
            'english' => 'en', 'zulu' => 'zu', 'xhosa' => 'xh', 'afrikaans' => 'af', 'sesotho' => 'st', 'sotho' => 'st', 'tswana' => 'tn',
            'swahili' => 'sw', 'hausa' => 'ha', 'yoruba' => 'yo', 'french' => 'fr', 'portuguese' => 'pt', 'shona' => 'sn', 'lingala' => 'ln',
        ][$language] ?? null);
    }
}
