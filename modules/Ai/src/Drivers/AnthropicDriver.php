<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Ai\Contracts\ChatTool;

/** Anthropic Messages API (POST /v1/messages). */
final class AnthropicDriver implements AiDriver, ChatDriver, VisionDriver
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

    public function see(string $model, string $system, string $prompt, string $image, string $mime, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
                    'messages' => [['role' => 'user', 'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($image)]],
                        ['type' => 'text', 'text' => $prompt],
                    ]]],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderFailed('Anthropic unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('Anthropic: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }

        return ['text' => (string) collect((array) $r->json('content'))->where('type', 'text')->pluck('text')->implode(''),
            'input_tokens' => (int) $r->json('usage.input_tokens'), 'output_tokens' => (int) $r->json('usage.output_tokens')];
    }

    public function chat(string $model, string $system, array $items, array $tools, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', array_filter([
                    'model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => self::messages($items),
                    'tools' => array_map(fn (ChatTool $t) => ['name' => $t->name, 'description' => $t->description, 'input_schema' => $t->parameters], $tools),
                ]));
        } catch (ConnectionException $e) {
            throw new ProviderFailed('Anthropic unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('Anthropic: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $blocks = collect((array) $r->json('content'));

        return [
            'text' => (string) $blocks->where('type', 'text')->pluck('text')->implode(''),
            'tool_calls' => ToolCalls::of($blocks->where('type', 'tool_use')->map(fn ($b) => [$b['id'] ?? '', $b['name'] ?? '', $b['input'] ?? []])),
            'input_tokens' => (int) $r->json('usage.input_tokens'), 'output_tokens' => (int) $r->json('usage.output_tokens'),
        ];
    }

    /**
     * Anthropic's shape: tool calls are "tool_use" blocks in the assistant's message; their results are "tool_result"
     * blocks in the next user message (consecutive results share one message).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function messages(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item['role'] === 'tool') {
                $block = ['type' => 'tool_result', 'tool_use_id' => (string) $item['tool_call_id'], 'content' => (string) json_encode($item['content'])];
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['role'] === 'user' && is_array($out[$last]['content']) && ($out[$last]['content'][0]['type'] ?? '') === 'tool_result') {
                    $out[$last]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }
            if ($item['role'] === 'assistant' && ! empty($item['tool_calls'])) {
                $content = (string) ($item['content'] ?? '') === '' ? [] : [['type' => 'text', 'text' => (string) $item['content']]];
                foreach ((array) $item['tool_calls'] as $call) {
                    $content[] = ['type' => 'tool_use', 'id' => (string) $call['id'], 'name' => (string) $call['name'], 'input' => (object) $call['arguments']];
                }
                $out[] = ['role' => 'assistant', 'content' => $content];

                continue;
            }
            $out[] = ['role' => (string) $item['role'], 'content' => (string) $item['content']];
        }

        return $out;
    }
}
