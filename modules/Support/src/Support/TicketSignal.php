<?php

declare(strict_types=1);

namespace Wurk\Support\Support;

use Wurk\Support\Models\SupportTicket;

/** Business health (16.5): what their support tickets say — waiting on us, unhappy, or struggling. */
final class TicketSignal
{
    /** @return list<array{key: string, points: int, reason: string, action: string}> */
    public function __invoke(string $tenantId): array
    {
        $out = [];
        $late = SupportTicket::query()->where('tenant_id', $tenantId)->whereIn('status', ['open', 'pending'])->whereNull('first_responded_at')
            ->where('first_response_due_at', '<', now())->count();
        if ($late > 0) {
            $out[] = ['key' => 'tickets_late', 'points' => 15, 'reason' => "{$late} ".($late === 1 ? 'ticket is' : 'tickets are').' waiting past our reply target', 'action' => 'Reply today'];
        }
        if (SupportTicket::query()->where('tenant_id', $tenantId)->where('rating', 'bad')->where('updated_at', '>=', now()->subDays(30))->exists()) {
            $out[] = ['key' => 'unhappy', 'points' => 15, 'reason' => 'Rated support “not good” this month', 'action' => 'Call to put it right'];
        }
        $recent = SupportTicket::query()->where('tenant_id', $tenantId)->where('created_at', '>=', now()->subDays(30))->count();
        if ($recent >= 3) {
            $out[] = ['key' => 'many_tickets', 'points' => 10, 'reason' => "{$recent} support tickets in 30 days", 'action' => 'Look for a pattern — offer a training session'];
        }

        return $out;
    }
}
