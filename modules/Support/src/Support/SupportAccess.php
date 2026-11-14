<?php

declare(strict_types=1);

namespace Wurk\Support\Support;

use Illuminate\Support\Facades\Mail;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Support\Mail\TicketMail;
use Wurk\Support\Models\SupportAccessGrant;
use Wurk\Support\Models\SupportMessage;
use Wurk\Support\Models\SupportTicket;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Consent-based support access (Sprint 16.4, decision D): WURK asks from a ticket; an owner chooses view-only
 * (default) or full, for up to two hours; a banner shows on every screen while staff are in; the owner can end it at
 * once; sensitive actions stay blocked; everything staff do is audited as "WURK staff (on behalf of …)".
 */
final class SupportAccess implements SupportSessions
{
    public const MINUTES = [15, 30, 60, 120];

    /** Never done by WURK staff, even with full access (route names; GET pages stay viewable). */
    public const BLOCKED_ROUTES = ['tenant.payouts*', 'tenant.payments.*', 'tenant.billing.*', 'tenant.credits.*', 'tenant.security*', 'tenant.team*',
        'tenant.referrals.settings.wallet', 'tenant.records*', 'tenant.support.access*'];

    /** @var array<string, array{id: string, level: 'view'|'full', staff_name: string, business: string, ends_at: \DateTimeInterface, ticket: int}|null> per request: asked on every permission check */
    private array $memo = [];

    public function __construct(
        private readonly AuditLog $audit,
        private readonly PeopleDirectory $people,
        private readonly TenantDirectory $tenants,
        private readonly TenantContext $context,
        private readonly TeamDirectory $team,
    ) {}

    public function active(string $tenantId, string $identityId): ?array
    {
        $key = "{$tenantId}:{$identityId}";
        if (array_key_exists($key, $this->memo)) {
            $hit = $this->memo[$key];

            return $hit !== null && $hit['ends_at'] > now() ? $hit : null;
        }
        $g = SupportAccessGrant::query()->where('tenant_id', $tenantId)->where('staff_id', $identityId)->where('status', 'active')
            ->where('ends_at', '>', now())->orderByDesc('ends_at')->orderByDesc('id')->first();

        return $this->memo[$key] = $g === null ? null : [
            'id' => $g->id, 'level' => $g->level === 'full' ? 'full' : 'view', 'staff_name' => $this->people->names([$g->staff_id])[$g->staff_id] ?? 'WURK support',
            'business' => $this->tenants->find($tenantId)?->displayName() ?? 'the business', 'ends_at' => $g->ends_at ?? now(),
            'ticket' => (int) SupportTicket::query()->whereKey($g->ticket_id)->value('number'),
        ];
    }

    /** Forget what this request learnt (a new business, or a new request in a long-running worker). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** The open request or live access for a ticket, if any. */
    public function forTicket(SupportTicket $ticket): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()->where('ticket_id', $ticket->id)
            ->where(fn ($q) => $q->where('status', 'requested')->orWhere(fn ($q) => $q->where('status', 'active')->where('ends_at', '>', now())))
            ->orderByDesc('id')->first(); // ULIDs: newest first, even within one second
    }

    /** WURK asks; the business's owners are told (email, and a banner in the back office). */
    public function request(SupportTicket $ticket, string $staffId, string $level, string $reason): SupportAccessGrant
    {
        SupportAccessGrant::query()->where('ticket_id', $ticket->id)->where('status', 'requested')->update(['status' => 'declined', 'decided_at' => now()]); // superseded
        $g = SupportAccessGrant::query()->create(['ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id, 'staff_id' => $staffId,
            'requested_level' => $level, 'reason' => mb_substr(trim($reason), 0, 500), 'status' => 'requested']);
        $what = $level === 'full' ? 'full access' : 'view-only access';
        SupportMessage::query()->create(['ticket_id' => $ticket->id, 'author_type' => 'staff', 'author_id' => $staffId, 'created_at' => now(),
            'body' => "To help with this, we’d like {$what} to your back office: {$g->reason}\n\nNothing happens unless you approve it (Support → Access). You choose view-only or full, and for how long; you can end it at any time."]);
        $this->audit->record('support.access_requested', 'support_ticket', $ticket->id, ['level' => $level], ['platform' => true]);
        $tenant = $this->tenants->find($ticket->tenant_id);
        if ($tenant !== null) {
            $emails = $this->context->run($tenant, fn () => $this->team->emailsWithPermission('support.access'));
            if ($emails !== []) {
                Mail::to($emails)->send(new TicketMail("WURK support asks for {$what} ({$ticket->reference()})", ["To help with “{$ticket->subject}”: {$g->reason}", '', 'Approve or decline:'],
                    route('tenant.support.access', ['tenant' => $tenant->handle()])));
            }
        }

        return $g;
    }

    public function approve(SupportAccessGrant $g, string $by, string $level, int $minutes): void
    {
        $g->update(['status' => 'active', 'level' => $level, 'minutes' => $minutes, 'decided_by' => $by, 'decided_at' => now(), 'ends_at' => now()->addMinutes($minutes)]);
        $this->memo = [];
        $this->audit->record('support.access_granted', 'support_access', $g->id, ['level' => $level, 'minutes' => $minutes, 'staff' => $g->staff_id]);
        $email = $this->people->contacts([$g->staff_id])[$g->staff_id]['email'] ?? null;
        $tenant = $this->tenants->find($g->tenant_id);
        if ($email !== null && $tenant !== null) {
            Mail::to($email)->send(new TicketMail('Access approved: '.$tenant->displayName(), [($level === 'full' ? 'Full' : 'View-only')." access for {$minutes} minutes."], route('tenant.home', ['tenant' => $tenant->handle()])));
        }
    }

    public function decline(SupportAccessGrant $g, string $by): void
    {
        $g->update(['status' => 'declined', 'decided_by' => $by, 'decided_at' => now()]);
        $this->audit->record('support.access_declined', 'support_access', $g->id, ['staff' => $g->staff_id]);
    }

    /** Ended by the business (at once) or by WURK when done. */
    public function end(SupportAccessGrant $g, string $by, bool $byWurk = false): void
    {
        $g->update(['status' => 'ended', 'ended_by' => $by, 'ended_at' => now(), 'ends_at' => min($g->ends_at ?? now(), now())]);
        $this->memo = [];
        $this->audit->record('support.access_ended', 'support_access', $g->id, ['staff' => $g->staff_id, 'by' => $byWurk ? 'WURK' : 'business'], ['platform' => $byWurk]);
    }

    /** For the business: the pending request or live access now, if any. */
    public function current(string $tenantId): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('status', 'requested')->orWhere(fn ($q) => $q->where('status', 'active')->where('ends_at', '>', now())))
            ->orderByDesc('id')->first();
    }
}
