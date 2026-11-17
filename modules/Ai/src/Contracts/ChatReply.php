<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/** The outcome of one conversational turn (Sprint 19.1). */
final readonly class ChatReply
{
    /**
     * @param  string  $text  what the AI said to the person
     * @param  list<array<string, mixed>>  $transcript  the turn's new items (assistant messages, tool calls and results), to store with the conversation
     * @param  list<array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}>  $calls  the tools it used, in order
     * @param  bool  $finished  false when it ran out of tool steps without answering
     */
    public function __construct(public string $text, public array $transcript, public array $calls, public bool $finished = true) {}
}
