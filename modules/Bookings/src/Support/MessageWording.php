<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

/**
 * Fixed message wording (owners choose channels, not words — agreed with WURK). WhatsApp uses
 * Meta-approved templates with the same wording; WURK registers them once (wurk_booking_<kind>),
 * with parameters in this order: client, service, business, when, link.
 */
final class MessageWording
{
    public const KINDS = ['confirmed', 'reminder', 'moved', 'cancelled'];

    private const TEXT = [
        'confirmed' => 'Hi {client}, your {service} booking at {business} is confirmed for {when}. Change or cancel: {link}',
        'reminder' => 'Hi {client}, a reminder of your {service} booking at {business} on {when}. Change or cancel: {link}',
        'moved' => 'Hi {client}, your {service} booking at {business} has moved to {when}. Change or cancel: {link}',
        'cancelled' => 'Hi {client}, your {service} booking at {business} on {when} is cancelled.',
    ];

    private const SUBJECT = [
        'confirmed' => 'Booking confirmed — {business}',
        'reminder' => 'Reminder: your booking at {business}',
        'moved' => 'Your booking has moved — {business}',
        'cancelled' => 'Booking cancelled — {business}',
    ];

    /** @param  array{client: string, service: string, business: string, when: string, link: string}  $vars */
    public static function text(string $kind, array $vars): string
    {
        return strtr(self::TEXT[$kind], self::placeholders($vars));
    }

    /** @param  array{client: string, service: string, business: string, when: string, link: string}  $vars */
    public static function subject(string $kind, array $vars): string
    {
        return strtr(self::SUBJECT[$kind], self::placeholders($vars));
    }

    /**
     * WhatsApp template parameters, in the registered order.
     *
     * @param  array{client: string, service: string, business: string, when: string, link: string}  $vars
     * @return list<string>
     */
    public static function whatsappParameters(string $kind, array $vars): array
    {
        $params = [$vars['client'], $vars['service'], $vars['business'], $vars['when']];

        return $kind === 'cancelled' ? $params : [...$params, $vars['link']];
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function placeholders(array $vars): array
    {
        $out = [];
        foreach ($vars as $k => $v) {
            $out['{'.$k.'}'] = $v;
        }

        return $out;
    }
}
