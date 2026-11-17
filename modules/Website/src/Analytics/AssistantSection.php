<?php

declare(strict_types=1);

namespace Wurk\Website\Analytics;

use Wurk\Platform\Support\ReportPeriod;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Support\Receptionist;

/**
 * Reports → Website chat assistant (Sprint 19.4b): conversations with customers, the bookings and orders it made,
 * chats handed to a person, and the credits it used. The owner's test chats aren't counted.
 */
final readonly class AssistantSection
{
    public function __construct(private Receptionist $receptionist) {}

    /** @return array<string, mixed>|null null when the assistant has never been used */
    public function build(ReportPeriod $p): ?array
    {
        $customers = fn () => ReceptionistConversation::query()->where('channel', '!=', Receptionist::TRY);
        if (! $this->receptionist->settings()['enabled'] && ! $customers()->exists()) {
            return null;
        }
        $in = fn ($from, $to) => $customers()->whereBetween('created_at', [$from, $to]);
        $count = fn ($from, $to, ?array $outcomes = null) => $in($from, $to)->when($outcomes !== null, fn ($q) => $q->whereIn('outcome', $outcomes))->count();
        $credits = fn ($from, $to) => round(((int) $in($from, $to)->sum('credits_milli')) / 1000, 1);
        [$from, $to, $pf, $pt] = [$p->from, $p->to, $p->previousFrom(), $p->previousTo()];
        $chats = $count($from, $to);
        $done = $count($from, $to, ['booked', 'ordered']);
        $handed = $count($from, $to, ['handed_over']);
        $highlights = [];
        if ($done > 0) {
            $highlights[] = "The assistant made {$done} ".($done === 1 ? 'booking or order' : 'bookings or orders').' for you.';
        }
        if ($chats > 0 && $handed / $chats >= 0.3) {
            $highlights[] = 'Many chats were passed to a person: teaching the assistant your answers (Customers → Chats → Teach it) helps.';
        }

        return [
            'title' => 'Website chat assistant',
            'metrics' => [
                ['label' => 'Conversations', 'value' => $chats, 'previous' => $count($pf, $pt), 'format' => 'count'],
                ['label' => 'Bookings and orders it made', 'value' => $done, 'previous' => $count($pf, $pt, ['booked', 'ordered']), 'format' => 'count'],
                ['label' => 'Passed to a person', 'value' => $handed, 'previous' => $count($pf, $pt, ['handed_over']), 'format' => 'count'],
                ['label' => 'Credits used', 'value' => $credits($from, $to), 'previous' => $credits($pf, $pt), 'format' => 'count'],
            ],
            'chart' => null,
            'rows' => null,
            'highlights' => $highlights,
        ];
    }
}
