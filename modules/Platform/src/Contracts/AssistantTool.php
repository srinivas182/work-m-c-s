<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Something "Ask WURK" (Sprint 16.2) may use on a person's behalf. Each module offers its own tools; the assistant only
 * offers a tool to someone whose role has its permission and whose package includes its feature.
 */
interface AssistantTool
{
    /** snake_case, unique, e.g. "create_coupon" */
    public function name(): string;

    /** What it does, for the AI (one or two plain sentences). */
    public function description(): string;

    /** @return array<string, string> parameter => what to put there (for the AI) */
    public function parameters(): array;

    /** The permission a person needs, or null when anyone in the back office may use it. */
    public function permission(): ?string;

    /** The package feature it needs, or null. */
    public function entitlement(): ?string;
}
