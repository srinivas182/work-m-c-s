<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini listens to the audio (generateContent with the audio inline) and returns the transcript, its language and how
 * sure it is, as JSON. Works with the same API key as Gemini's text models.
 */
final class GoogleSpeechDriver implements SpeechDriver
{
    private const PROMPT = 'Transcribe this voice note word for word, in the language spoken (it may be an African language such as isiZulu, isiXhosa, Afrikaans, Sesotho, Setswana, Kiswahili, Hausa or Yoruba, or French or English, or a mix). Do not translate. Reply with JSON only: {"text": the transcript, "language": its ISO 639-1 code, "confidence": a number from 0 to 1 for how sure you are of the words}. If nothing intelligible is said, use an empty text and confidence 0.';

    public function transcribe(string $model, string $audio, string $mime, string $apiKey): array
    {
        try {
            $r = Http::timeout(90)->acceptJson()->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'contents' => [['role' => 'user', 'parts' => [['inline_data' => ['mime_type' => strtok($mime, ';') ?: 'audio/ogg', 'data' => base64_encode($audio)]], ['text' => self::PROMPT]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 2000],
                ]);
        } catch (ConnectionException) {
            throw new ProviderFailed('Google unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('Google: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $json = json_decode((string) collect((array) $r->json('candidates.0.content.parts'))->pluck('text')->implode(''), true);
        if (! is_array($json) || ! is_string($json['text'] ?? null)) {
            throw new ProviderFailed('Google: no transcript returned');
        }
        $language = is_string($json['language'] ?? null) && preg_match('/^[a-z]{2}$/', strtolower($json['language'])) === 1 ? strtolower($json['language']) : null;

        return ['text' => trim($json['text']), 'language' => $language, 'confidence' => is_numeric($json['confidence'] ?? null) ? max(0.0, min(1.0, (float) $json['confidence'])) : null];
    }
}
