<?php

declare(strict_types=1);

namespace Wurk\Website\Receptionist;

use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Website\Support\ChatBot;

/** The owner's own answers: the chat's buttons and keyword answers (and any taught from a person's reply). */
final readonly class FaqTool implements ReceptionistTool
{
    public function __construct(private ChatBot $bot) {}

    public function name(): string
    {
        return 'owner_answers';
    }

    public function description(): string
    {
        return 'Answers the owner wrote themselves (policies, parking, payment methods, what to bring…). Prefer these over anything else when one fits the question.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
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
        $c = $this->bot->publicConfig();
        $answers = [];
        foreach ($c['buttons'] as $b) {
            $answers[] = ['question' => $b['label'], 'answer' => $b['answer']];
        }
        foreach ($c['keywords'] as $k) {
            $answers[] = ['about' => implode(', ', $k['words']), 'answer' => $k['answer']];
        }

        return ['answers' => $answers, 'reply_time_for_people' => $c['reply'], 'how_to_use' => 'An answer here is the owner’s own words: use it as written.'];
    }
}
