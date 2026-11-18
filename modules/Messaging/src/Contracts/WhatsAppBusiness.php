<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/**
 * The current business's own WhatsApp number (Sprint 21), for other modules: send replies inside the 24-hour customer
 * service window, and fetch media customers sent. Inbound messages arrive as Messaging events
 * (`WhatsAppReceived`, `WhatsAppStatusChanged`), dispatched inside the business's context.
 */
interface WhatsAppBusiness
{
    /** Whether the current business has connected its own number. */
    public function connected(): bool;

    /** Sends a free-form text reply (only inside the 24-hour window; WhatsApp refuses it outside). Costs credits. */
    public function sendText(string $to, string $text): SendResult;

    /**
     * Sends a photo or document the business chose (uploaded to Meta first). Costs credits.
     *
     * @param  'image'|'document'  $kind
     */
    public function sendMedia(string $to, string $kind, string $contents, string $mime, string $filename, string $caption = ''): SendResult;

    /**
     * Sends one of WhatsApp's interactive messages (inside the 24-hour window): reply buttons, a list, or a link button
     * — the `interactive` object of Meta's Cloud API (21.3). Costs credits.
     *
     * @param  array<string, mixed>  $interactive
     */
    public function sendInteractive(string $to, array $interactive): SendResult;

    /** Whether Meta has approved the business's follow-up template, for reaching a customer after the 24-hour window. */
    public function followUpReady(): bool;

    /**
     * Sends the approved follow-up template ("Hi {name}, this is {business} following up on your message…"). Costs credits.
     */
    public function sendFollowUp(string $to, string $name): SendResult;

    /** The follow-up template's words for this customer, as the customer sees them. */
    public function followUpText(string $name): string;

    /**
     * A file a customer sent: its bytes and type, or null when Meta no longer has it.
     *
     * @return array{contents: string, mime: string}|null
     */
    public function media(string $mediaId): ?array;
}
