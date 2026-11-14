<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * A change made on request. Nothing happens until the person has seen exactly what will change and confirmed; the
 * change is logged and, where it still can be, undone.
 */
interface AssistantActionTool extends AssistantTool
{
    /**
     * Exactly what will change — checked as the screen would check it, so confirming can't fail on bad input.
     *
     * @param  array<string, mixed>  $params
     * @return array{summary: string, changes: list<string>}
     *
     * @throws AssistantRefused with what's wrong (e.g. "You have two team members called Sam — which one?")
     */
    public function preview(array $params): array;

    /**
     * Make the change (the same checks as preview run again first).
     *
     * @param  array<string, mixed>  $params
     * @return array{done: string, link: string|null, undo: array<string, mixed>}
     *
     * @throws AssistantRefused
     */
    public function apply(array $params): array;

    /**
     * Put things back. False when it can't be undone any more (e.g. the coupon has been used, the draft was sent).
     *
     * @param  array<string, mixed>  $undo  what apply() returned
     */
    public function undo(array $undo): bool;
}
