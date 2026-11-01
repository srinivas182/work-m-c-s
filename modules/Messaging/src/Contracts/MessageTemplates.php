<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/**
 * Customer messages and their wording. Modules define their messages (with today's wording as the standard);
 * a business may reword email and SMS within the safety rails; WhatsApp stays fixed (Meta-approved templates).
 */
interface MessageTemplates
{
    /**
     * @param  array{group: string, label: string, placeholders: list<string>, required: list<string>, subject: string, text: string, sample: array<string, string>, whatsapp?: bool, optional?: bool}  $definition
     */
    public function define(string $key, array $definition): void;

    /**
     * The subject for an email (the business's wording, else the standard), placeholders filled.
     *
     * @param  array<string, string>  $vars
     */
    public function subject(string $key, array $vars): string;

    /**
     * The text for a channel (email or sms), placeholders filled.
     *
     * @param  array<string, string>  $vars
     */
    public function text(string $key, string $channel, array $vars): string;
}
