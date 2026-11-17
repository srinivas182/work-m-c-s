<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Ai\Contracts\ChatTool;

/** Google Gemini API (generateContent). Thinking tokens are billed as output. */
final class GoogleDriver implements AiDriver, ChatDriver
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

    public function chat(string $model, string $system, array $items, array $tools, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', array_filter([
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => self::contents($items),
                    'tools' => $tools === [] ? null : [['functionDeclarations' => array_map(fn (ChatTool $t) => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->parameters], $tools)]],
                    'generationConfig' => ['maxOutputTokens' => $maxTokens],
                ]));
        } catch (ConnectionException $e) {
            throw new ProviderFailed('Google unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('Google: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $parts = collect((array) $r->json('candidates.0.content.parts'));

        return [
            'text' => (string) $parts->pluck('text')->filter()->implode(''),
            // Google's calls have no id: one is made up, and results are matched back by position and name.
            'tool_calls' => ToolCalls::of($parts->filter(fn ($p) => isset($p['functionCall']))->values()->map(fn ($p, $i) => [
                'g'.$i.'-'.$p['functionCall']['name'], $p['functionCall']['name'] ?? '', $p['functionCall']['args'] ?? [],
            ])),
            'input_tokens' => (int) $r->json('usageMetadata.promptTokenCount'),
            'output_tokens' => (int) $r->json('usageMetadata.candidatesTokenCount') + (int) $r->json('usageMetadata.thoughtsTokenCount'),
        ];
    }

    /**
     * Gemini's shape: "user" and "model" turns; calls are functionCall parts, results functionResponse parts.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function contents(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item['role'] === 'tool') {
                $part = ['functionResponse' => ['name' => (string) $item['name'], 'response' => (object) $item['content']]];
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['role'] === 'user' && isset($out[$last]['parts'][0]['functionResponse'])) {
                    $out[$last]['parts'][] = $part;
                } else {
                    $out[] = ['role' => 'user', 'parts' => [$part]];
                }

                continue;
            }
            $parts = (string) ($item['content'] ?? '') === '' ? [] : [['text' => (string) $item['content']]];
            foreach ((array) ($item['tool_calls'] ?? []) as $call) {
                $parts[] = ['functionCall' => ['name' => (string) $call['name'], 'args' => (object) $call['arguments']]];
            }
            $out[] = ['role' => $item['role'] === 'assistant' ? 'model' : 'user', 'parts' => $parts];
        }

        return $out;
    }
}
