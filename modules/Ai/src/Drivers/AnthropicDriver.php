<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Anthropic Messages API (POST /v1/messages). */
final class AnthropicDriver implements AiDriver
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderFailed('Anthropic unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('Anthropic: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $text = collect((array) $r->json('content'))->where('type', 'text')->pluck('text')->implode('');

        return ['text' => (string) $text, 'input_tokens' => (int) $r->json('usage.input_tokens'), 'output_tokens' => (int) $r->json('usage.output_tokens')];
    }
}
