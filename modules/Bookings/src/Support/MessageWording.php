<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

/**
 * The standard wording (Sprint 12.5: owners may reword email and SMS in Settings → Messages; WhatsApp uses
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

    /**
     * The booking messages for Settings → Messages: today's wording is the standard.
     *
     * @return array<string, array{group: string, label: string, placeholders: list<string>, required: list<string>, subject: string, text: string, sample: array<string, string>, whatsapp: bool}>
     */
    public static function definitions(): array
    {
        $labels = ['confirmed' => 'Booking confirmed', 'reminder' => 'Booking reminder', 'moved' => 'Booking moved', 'cancelled' => 'Booking cancelled'];
        $out = [];
        foreach (self::KINDS as $kind) {
            $out['booking.'.$kind] = ['group' => 'Bookings', 'label' => $labels[$kind], 'placeholders' => ['client', 'service', 'business', 'when', 'link'],
                'required' => $kind === 'cancelled' ? [] : ['link'], 'subject' => self::SUBJECT[$kind], 'text' => self::TEXT[$kind],
                'sample' => ['client' => 'Thandi', 'service' => 'Knotless braids', 'business' => 'Glow Salon', 'when' => 'Sat 3 May at 10:00', 'link' => 'https://glow-salon.wurk.co.za/b/abc123'],
                'whatsapp' => true];
        }

        return $out;
    }

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
