<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Something a business's AI receptionist (Sprint 19.2) may do for a customer: look up free times, book, find a
 * booking, take an order… Each module offers its own tools. The receptionist only offers a tool when the business's
 * package includes its feature and the owner allowed its capability. Tools run inside the business (tenant context)
 * and must only ever return that business's public facts or the current customer's own records.
 */
interface ReceptionistTool
{
    /** What the owner must allow: "answer" (always on), "book", "change" (move or cancel) or "order". */
    public const CAPABILITIES = ['answer', 'book', 'change', 'order'];

    /** snake_case, unique, e.g. "free_times" */
    public function name(): string;

    /** What it does and when to use it, for the AI (one or two plain sentences). */
    public function description(): string;

    /** @return array<string, mixed> its parameters as a JSON schema of type "object" */
    public function parameters(): array;

    public function capability(): string;

    /** The package feature it needs, or null. */
    public function entitlement(): ?string;

    /**
     * Run it. Return what the AI should see (facts, not prose). Throw ReceptionistRefused with a message for the AI
     * when it can't be done (a time just taken, details missing) — the AI tells the customer in its own words.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array{conversation: string, channel: string, customer: array{name?: string|null, phone?: string|null, email?: string|null, verified?: bool}}  $context
     * @return array<string, mixed>
     *
     * @throws ReceptionistRefused
     */
    public function run(array $arguments, array $context): array;
}
