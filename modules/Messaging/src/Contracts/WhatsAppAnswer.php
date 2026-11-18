<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/** What the customer sees after tapping a button (21.5): a message, and maybe more to tap. */
final readonly class WhatsAppAnswer
{
    /** @param  list<array{id: string, title: string, description?: string}>  $choices */
    public function __construct(public string $text, public array $choices = []) {}
}
