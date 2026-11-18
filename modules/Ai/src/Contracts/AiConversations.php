<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/**
 * A conversation with tools (Sprint 19.1) — the engine behind the AI receptionist. Items are provider-neutral:
 *
 * - `['role' => 'user', 'content' => '…']`
 * - `['role' => 'assistant', 'content' => '…', 'tool_calls' => [['id' => '…', 'name' => '…', 'arguments' => [...]]]]`
 * - `['role' => 'tool', 'tool_call_id' => '…', 'name' => '…', 'content' => [...]]`
 *
 * The AI asks for tools; `$runTool(name, arguments)` runs each and returns what the AI sees. It may take several tool
 * steps before answering. Every step is logged and costed like any other WURK AI request; the task's model is tried
 * first, then the fallbacks — a provider failing mid-turn hands the transcript so far to the next one (tools already
 * run are never run again).
 */
interface AiConversations
{
    /**
     * @param  list<array<string, mixed>>  $items  the conversation so far, ending with the person's message
     * @param  list<ChatTool>  $tools
     * @param  callable(string, array<string, mixed>): array<string, mixed>  $runTool
     *
     * @throws AiUnavailable when every provider failed or a cap was reached
     */
    public function converse(string $task, string $system, array $items, array $tools, callable $runTool, ?string $tenantId = null, int $maxSteps = 6, int $maxTokens = 1200): ChatReply;

    /**
     * How a conversation task went over a period (19.7, for Watchtower): its provider steps, the failed ones (a fallback
     * may have answered), the slow ones, and the slowest.
     *
     * @return array{steps: int, failed: int, slow: int, slowest_ms: int}
     */
    public function health(string $task, \DateTimeInterface $from, \DateTimeInterface $to, int $slowMs): array;
}
