<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/**
 * A tool the AI may call during a conversation (Sprint 19.1): its name, what it's for, and its parameters as a JSON
 * schema object. The caller runs it — the AI only asks — so every tool runs with the caller's permissions and tenant.
 */
final readonly class ChatTool
{
    /** @param  array<string, mixed>  $parameters  a JSON schema of type "object" */
    public function __construct(public string $name, public string $description, public array $parameters = ['type' => 'object', 'properties' => new \stdClass]) {}
}
