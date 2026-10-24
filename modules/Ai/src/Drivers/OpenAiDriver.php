<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI Responses API (POST /v1/responses). */
final class OpenAiDriver implements AiDriver
{
    public function name(): string
    {
        return 'openai';
    }

    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withToken($apiKey)
                ->post('https://api.openai.com/v1/responses', ['model' => $model, 'instructions' => $system, 'input' => $prompt, 'max_output_tokens' => $maxTokens]);
        } catch (ConnectionException $e) {
            throw new ProviderFailed('OpenAI unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('OpenAI: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $text = collect((array) $r->json('output'))->where('type', 'message')->flatMap(fn ($m) => (array) ($m['content'] ?? []))
            ->where('type', 'output_text')->pluck('text')->implode('');

        return ['text' => (string) $text, 'input_tokens' => (int) $r->json('usage.input_tokens'), 'output_tokens' => (int) $r->json('usage.output_tokens')];
    }
}
