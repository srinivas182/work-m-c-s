<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/**
 * WURK's own WhatsApp number (Admin → Providers → WhatsApp): free-form replies inside the 24-hour window after
 * someone wrote, approved templates after it, and the media people send to it (the help line's voice notes, 25.6).
 */
interface WurkWhatsApp
{
    /** The template WURK support's replies go out with once the 24-hour window has closed (25.6). */
    public const HELP_REPLY = 'wurk_help_reply';

    public function available(): bool;

    public function sendText(string $to, string $text): bool;

    /** @param  list<string>  $parameters */
    public function sendTemplate(string $to, string $template, array $parameters, string $language = 'en'): bool;

    /**
     * A message with up to three reply buttons, inside the 24-hour window (26.1). The tap arrives as a
     * `WurkWhatsAppReceived` with `buttonId`.
     *
     * @param  array<string, string>  $buttons  id => title
     */
    public function sendButtons(string $to, string $body, array $buttons): bool;

    /** @return array{contents: string, mime: string}|null a file sent to WURK's number */
    public function media(string $mediaId): ?array;
}
