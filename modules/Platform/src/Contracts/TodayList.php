<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * The team's Today in the WURK app (Sprint 26.2): each module adds what this person has to do today — their next
 * bookings, chats waiting, orders to pack — with the buttons to do it right there.
 *
 * A section: ['title' => string, 'empty' => string|null, 'items' => list<item>, 'link' => ['label' => string, 'url' => string]|null]
 * An item: ['id' => string, 'title' => string, 'detail' => string, 'time' => string|null, 'badge' => string|null,
 *   'url' => string|null, 'actions' => list<['label' => string, 'url' => string, 'data' => array, 'tone' => 'primary'|'secondary'|'danger']>]
 */
interface TodayList
{
    /** @param \Closure(string $identityId): (array<string, mixed>|null) $section null when it's not for this person */
    public function register(string $key, int $order, \Closure $section): void;

    /** @return list<array<string, mixed>> */
    public function for(string $identityId): array;
}
