<?php

declare(strict_types=1);

namespace Wurk\Website\Receptionist;

use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Support\ChatBot;

/** Pass the chat to a person at the business: their details, the message, and the conversation so far. */
final readonly class HandOverTool implements ReceptionistTool
{
    public function __construct(private ChatBot $bot) {}

    public function name(): string
    {
        return 'hand_over';
    }

    public function description(): string
    {
        return 'Pass the conversation to a person at the business, who will reply by phone or email. Use it when the customer asks for a person, when you can’t help, or for anything sensitive (complaints, health, money disputes). First ask for their name and a phone number or email, and their agreement to be contacted.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'description' => 'The customer’s name'],
            'phone' => ['type' => 'string', 'description' => 'Their mobile number, if given'],
            'email' => ['type' => 'string', 'description' => 'Their email, if given'],
            'message' => ['type' => 'string', 'description' => 'What they need, in a sentence'],
            'agreed_to_be_contacted' => ['type' => 'boolean', 'description' => 'True only if they said yes to being contacted about this'],
        ], 'required' => ['name', 'message', 'agreed_to_be_contacted']];
    }

    public function capability(): string
    {
        return 'answer';
    }

    public function entitlement(): ?string
    {
        return null;
    }

    public function run(array $arguments, array $context): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $phone = trim((string) ($arguments['phone'] ?? '')) ?: null;
        $email = trim((string) ($arguments['email'] ?? '')) ?: null;
        if ($name === '' || ($phone === null && $email === null)) {
            throw new ReceptionistRefused('Ask for their name and a phone number or email first.');
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ReceptionistRefused('That email doesn’t look right — ask them to check it.');
        }
        if ($phone !== null && preg_match('/^[+0-9 ()-]{6,20}$/', $phone) !== 1) {
            throw new ReceptionistRefused('That phone number doesn’t look right — ask them to check it.');
        }
        if (($arguments['agreed_to_be_contacted'] ?? false) !== true) {
            throw new ReceptionistRefused('Ask whether they agree to be contacted about this before passing it on.');
        }
        $conversation = ReceptionistConversation::query()->findOrFail($context['conversation']);
        $transcript = ReceptionistMessage::query()->where('conversation_id', $conversation->id)->whereIn('role', ['user', 'assistant'])->orderBy('position')
            ->get()->map(fn (ReceptionistMessage $m) => ['from' => $m->role === 'user' ? 'visitor' : 'bot', 'text' => mb_substr((string) ($m->content['content'] ?? ''), 0, 600)])
            ->filter(fn (array $l) => $l['text'] !== '')->take(-30)->values()->all();
        $transcript = array_values($transcript);
        $reply = $this->bot->handOff(['name' => mb_substr($name, 0, 80), 'phone' => $phone, 'email' => $email === null ? null : mb_substr($email, 0, 120),
            'message' => mb_substr((string) ($arguments['message'] ?? ''), 0, 1000)], $transcript, $conversation->visitor, false);
        $conversation->update(['status' => 'waiting', 'outcome' => 'handed_over', 'keep' => true, 'name' => mb_substr($name, 0, 80), 'phone' => $phone, 'email' => $email]);

        return ['handed_over' => true, 'tell_the_customer' => $reply];
    }
}
