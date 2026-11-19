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
    /** WURK's templates on every connected account (submitted for Meta's review on connect). */
    public const FOLLOW_UP = 'wurk_follow_up';

    /** A booking reminder with Confirm / Move / Cancel buttons (21.5): customer's first name, service, business, when. */
    public const REMINDER = 'wurk_reminder';

    /** A request to pay, and its reminders (23.5a): customer's first name, business, amount, what for, the pay link. */
    public const PAYMENT_REQUEST = 'wurk_payment_request';

    /** "How was it?" after a visit or order (25.3): customer's first name, business, what it was, the review link. */
    public const REVIEW_REQUEST = 'wurk_review_request';

    /** Whether the current business has connected its own number. */
    public function connected(): bool;

    /** The connected number in international digits (for wa.me links), or null. */
    public function number(): ?string;

    /** The message a customer's chat starts with from the website's button or the QR code (21.5). */
    public function chatText(): string;

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

    /**
     * The reply with things to tap: up to 3 as reply buttons, up to 10 as a list (more are left out). Each choice's
     * `id` comes back with the customer's tap (21.5). Costs credits.
     *
     * @param  list<array{id: string, title: string, description?: string}>  $choices
     */
    public function sendChoices(string $to, string $text, array $choices): SendResult;

    /** Whether Meta has approved one of WURK's templates (above) on the business's account. */
    public function templateReady(string $name): bool;

    /**
     * Sends one of WURK's approved templates, with its body's values and, for each quick-reply button, the payload that
     * comes back when it's tapped (21.5). Costs credits.
     *
     * @param  list<string>  $parameters
     * @param  list<string>  $buttonPayloads
     */
    public function sendTemplate(string $to, string $name, array $parameters, array $buttonPayloads = []): SendResult;

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
