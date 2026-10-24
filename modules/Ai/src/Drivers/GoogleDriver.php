<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Google Gemini API (generateContent). Thinking tokens are billed as output. */
final class GoogleDriver implements AiDriver
{
    public function name(): string
    {
        return 'google';
    }

    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['maxOutputTokens' => $maxTokens],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderFailed('Google unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('Google: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $text = collect((array) $r->json('candidates.0.content.parts'))->pluck('text')->implode('');

        return [
            'text' => (string) $text,
            'input_tokens' => (int) $r->json('usageMetadata.promptTokenCount'),
            'output_tokens' => (int) $r->json('usageMetadata.candidatesTokenCount') + (int) $r->json('usageMetadata.thoughtsTokenCount'),
        ];
    }
}
