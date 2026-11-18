<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wurk\Ai\Contracts\ChatTool;

/** OpenAI Responses API (POST /v1/responses). */
final class OpenAiDriver implements AiDriver, ChatDriver, VisionDriver
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

    public function see(string $model, string $system, string $prompt, string $image, string $mime, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withToken($apiKey)->post('https://api.openai.com/v1/responses', ['model' => $model, 'instructions' => $system, 'max_output_tokens' => $maxTokens,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_image', 'image_url' => 'data:'.$mime.';base64,'.base64_encode($image)], ['type' => 'input_text', 'text' => $prompt]]]]]);
        } catch (ConnectionException $e) {
            throw new ProviderFailed('OpenAI unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('OpenAI: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $text = collect((array) $r->json('output'))->where('type', 'message')->flatMap(fn ($m) => (array) ($m['content'] ?? []))->where('type', 'output_text')->pluck('text')->implode('');

        return ['text' => (string) $text, 'input_tokens' => (int) $r->json('usage.input_tokens'), 'output_tokens' => (int) $r->json('usage.output_tokens')];
    }

    public function chat(string $model, string $system, array $items, array $tools, int $maxTokens, string $apiKey): array
    {
        try {
            $r = Http::timeout(60)->acceptJson()->withToken($apiKey)->post('https://api.openai.com/v1/responses', array_filter([
                'model' => $model, 'instructions' => $system, 'input' => self::input($items), 'max_output_tokens' => $maxTokens,
                'tools' => array_map(fn (ChatTool $t) => ['type' => 'function', 'name' => $t->name, 'description' => $t->description, 'parameters' => $t->parameters], $tools),
            ]));
        } catch (ConnectionException $e) {
            throw new ProviderFailed('OpenAI unreachable');
        }
        if (! $r->successful()) {
            throw new ProviderFailed('OpenAI: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
        $output = collect((array) $r->json('output'));

        return [
            'text' => (string) $output->where('type', 'message')->flatMap(fn ($m) => (array) ($m['content'] ?? []))->where('type', 'output_text')->pluck('text')->implode(''),
            'tool_calls' => ToolCalls::of($output->where('type', 'function_call')->map(fn ($c) => [
                $c['call_id'] ?? '', $c['name'] ?? '', json_decode((string) ($c['arguments'] ?? '{}'), true),
            ])),
            'input_tokens' => (int) $r->json('usage.input_tokens'), 'output_tokens' => (int) $r->json('usage.output_tokens'),
        ];
    }

    /**
     * The Responses API's shape: messages, then "function_call" and "function_call_output" items.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function input(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item['role'] === 'tool') {
                $out[] = ['type' => 'function_call_output', 'call_id' => (string) $item['tool_call_id'], 'output' => (string) json_encode($item['content'])];

                continue;
            }
            if ((string) ($item['content'] ?? '') !== '') {
                $out[] = ['role' => (string) $item['role'], 'content' => (string) $item['content']];
            }
            foreach ((array) ($item['tool_calls'] ?? []) as $call) {
                $out[] = ['type' => 'function_call', 'call_id' => (string) $call['id'], 'name' => (string) $call['name'], 'arguments' => (string) json_encode((object) $call['arguments'])];
            }
        }

        return $out;
    }
}
