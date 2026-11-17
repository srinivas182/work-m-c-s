<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Wurk\Ai\Contracts\AiConversations;

/**
 * The AI receptionist and WURK's sales assistant, watched (Sprint 19.7): once an hour, the hour that ended. Many
 * failed AI steps (customers then get the rule-based chat), or several very slow ones, become a problem in Watchtower.
 * An AI reply that checks the calendar normally takes a few seconds, so the page budget (300 ms) doesn't apply.
 */
final readonly class AiChats
{
    /** A single AI step slower than this keeps a customer waiting too long. */
    public const SLOW_MS = 15_000;

    private const TASKS = ['receptionist' => 'the AI receptionist', 'sales' => 'WURK’s sales assistant'];

    public function __construct(private AiConversations $ai) {}

    public function evaluate(Recorder $recorder): int
    {
        $to = CarbonImmutable::now()->startOfHour();
        if (! Cache::add('watchtower:ai-chats:'.$to->format('YmdH'), true, now()->addHours(3))) {
            return 0; // this hour was looked at already
        }
        $from = $to->subHour();
        $when = $from->setTimezone('Africa/Johannesburg')->format('j M H:00');
        $found = 0;
        foreach (self::TASKS as $task => $name) {
            $h = $this->ai->health($task, $from, $to, self::SLOW_MS);
            if ($h['steps'] >= 10 && $h['failed'] / $h['steps'] >= 0.2) {
                $found++;
                $recorder->record(new Signal('check', "ai-chat-failing:{$task}", "Replies failing: {$name}", severity: 'high',
                    message: "{$h['failed']} of {$h['steps']} AI steps failed in the hour from {$when} SAST. When none of the providers answers, customers get the business’s own answers instead. Check Admin → WURK AI (keys, fallbacks, caps).",
                    location: "ai:{$task}", context: $h));
            }
            if ($h['slow'] >= 5) {
                $found++;
                $recorder->record(new Signal('slow', "ai-chat-slow:{$task}", "Slow replies: {$name}",
                    message: "{$h['slow']} AI steps took over ".(self::SLOW_MS / 1000)." seconds in the hour from {$when} SAST (slowest ".number_format($h['slowest_ms']).' ms).',
                    location: "ai:{$task}", context: $h));
            }
        }

        return $found;
    }
}
