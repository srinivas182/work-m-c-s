<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Wurk\Ai\Contracts\ChatTool;

/** A provider that can hold a conversation with tools (Sprint 19.1). Throws ProviderFailed for anything unusable. */
interface ChatDriver
{
    /**
     * @param  list<array<string, mixed>>  $items  provider-neutral items (see AiConversations)
     * @param  list<ChatTool>  $tools
     * @return array{text: string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, input_tokens: int, output_tokens: int}
     */
    public function chat(string $model, string $system, array $items, array $tools, int $maxTokens, string $apiKey): array;
}
