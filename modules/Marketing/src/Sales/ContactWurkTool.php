<?php

declare(strict_types=1);

namespace Wurk\Marketing\Sales;

use Wurk\Marketing\Actions\RecordContactMessage;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\SalesTool;

/** The sales assistant's hand-over to WURK (19.5): the same as the contact form, with the visitor's consent. */
final readonly class ContactWurkTool implements SalesTool
{
    public function name(): string
    {
        return 'contact_wurk';
    }

    public function description(): string
    {
        return 'Pass the visitor to someone at WURK, who replies within one working day: for questions you can’t answer, a demo, or when they ask for a person. Needs their name, a phone number or email, and their agreement to be contacted.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'contact' => ['type' => 'string', 'description' => 'Phone number or email'],
            'business' => ['type' => 'string', 'description' => 'Their business, if they said'],
            'message' => ['type' => 'string', 'description' => 'What they want, in a sentence or two'],
            'agreed_to_be_contacted' => ['type' => 'boolean'],
        ], 'required' => ['name', 'contact', 'message', 'agreed_to_be_contacted']];
    }

    public function available(): bool
    {
        return true;
    }

    public function run(array $arguments, array $context): array
    {
        $name = mb_substr(trim((string) ($arguments['name'] ?? '')), 0, 120);
        $contact = mb_substr(trim((string) ($arguments['contact'] ?? '')), 0, 255);
        $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
        if ($name === '' || (! $isEmail && preg_match('/^[+0-9 ()-]{6,20}$/', $contact) !== 1)) {
            throw new ReceptionistRefused('Ask for their name and a phone number or email.');
        }
        if (($arguments['agreed_to_be_contacted'] ?? false) !== true) {
            throw new ReceptionistRefused('Ask whether they agree to be contacted by WURK about this.');
        }
        app(RecordContactMessage::class)([
            'name' => $name, 'contact' => $contact, 'business' => mb_substr(trim((string) ($arguments['business'] ?? '')), 0, 120) ?: null,
            'message' => mb_substr(trim((string) ($arguments['message'] ?? '')), 0, 2900)."\n\n(Sent from the sales assistant chat.)",
        ], request()->ip());

        return ['handed_over' => true, 'tell_the_visitor' => 'Sent. Someone from WURK will get back to them within one working day.'];
    }
}
