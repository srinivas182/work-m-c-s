<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/**
 * Paid WURK AI actions for the current business: quote first (exact credits, balance
 * before and after, or why it can't run), then perform — charged once, refunded on failure.
 */
interface AiActions
{
    public const ACTIONS = [
        'generate_site' => ['label' => 'Write my website', 'task' => 'site_generation'],
        'rewrite' => ['label' => 'Rewrite this section', 'task' => 'rewrite'],
        'image' => ['label' => 'Generate an image', 'task' => 'image'],
    ];

    /** @return array{action: string, label: string, credits: float, free: bool, balance: float, after: float, allowed: bool, reason: string|null, can_buy: bool} */
    public function quote(string $action): array;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws AiActionRefused
     */
    public function perform(string $action, string $key, callable $work): mixed;
}
