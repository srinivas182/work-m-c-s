<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Push notifications to the WURK app on people's phones (Sprint 26.2). Modules say what happened; the Push module
 * finds who in the current business may see it and wants it, and sends it to their phones.
 */
interface PushNotifications
{
    /** What a person can switch on or off for themselves, per business. */
    public const KINDS = [
        'booking' => 'New bookings and changes',
        'chat' => 'A chat waiting for a person',
        'order' => 'New orders',
        'payment' => 'Payments received',
        'credits' => 'Credits running low',
    ];

    /**
     * Inside the business: tells the active team members who have `$permission` (everyone, when null), plus the
     * `$also` people (identity ids, e.g. the stylist who was booked) — each only if they want this kind. Never the
     * person who did it (`$except`). With `$branchId`, people limited to other branches aren't told.
     *
     * @param  list<string>  $also
     */
    public function notify(string $kind, string $title, string $body, string $url, ?string $permission = null, array $also = [], ?string $except = null, ?string $branchId = null): void;
}
