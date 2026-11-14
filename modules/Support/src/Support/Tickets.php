<?php

declare(strict_types=1);

namespace Wurk\Support\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Support\Mail\TicketMail;
use Wurk\Support\Models\SupportAttachment;
use Wurk\Support\Models\SupportMessage;
use Wurk\Support\Models\SupportTicket;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Support tickets (Sprint 16.3): opened from a business's back office, answered in WURK's inbox, emailed both ways.
 * Status: open (with WURK) → pending (with the business) → solved; any reply from the business reopens it.
 */
final readonly class Tickets
{
    public function __construct(
        private SupportTargets $targets,
        private Screenshots $screenshots,
        private PeopleDirectory $people,
        private TenantDirectory $tenants,
        private AuditLog $audit,
    ) {}

    /**
     * @param  array{page?: string|null, browser?: string|null, last_error?: string|null}  $context
     * @param  list<UploadedFile>  $files
     */
    public function open(string $tenantId, string $by, string $subject, string $body, array $context, array $files): SupportTicket
    {
        $target = $this->targets->forTenant($tenantId);
        $ticket = DB::transaction(function () use ($tenantId, $by, $subject, $target, $context): SupportTicket {
            $number = (int) (SupportTicket::query()->lockForUpdate()->max('number') ?? 1000) + 1;

            return SupportTicket::query()->create([
                'number' => max(1001, $number), 'tenant_id' => $tenantId, 'opened_by' => $by, 'subject' => mb_substr(trim($subject), 0, 150),
                'status' => 'open', 'priority' => 'normal', 'package_key' => $target['package_key'], 'target_hours' => $target['hours'],
                'first_response_due_at' => $this->targets->due(now(), $target['hours']),
                'page' => $this->clip($context['page'] ?? null, 200), 'browser' => $this->clip($context['browser'] ?? null, 300),
                'last_error' => $this->clip($context['last_error'] ?? null, 500), 'last_activity_at' => now(),
            ]);
        });
        $this->addMessage($ticket, 'business', $by, $body, $files);
        $this->audit->record('support.ticket_opened', 'support_ticket', $ticket->id, ['number' => $ticket->number]);
        $this->tellWurk($ticket, "New ticket {$ticket->reference()} from {$this->business($ticket)}: {$ticket->subject}", $body);

        return $ticket;
    }

    /** @param list<UploadedFile> $files */
    public function replyFromBusiness(SupportTicket $ticket, string $by, string $body, array $files): void
    {
        $this->addMessage($ticket, 'business', $by, $body, $files);
        $ticket->update(['status' => 'open', 'solved_at' => null, 'last_activity_at' => now()]);
        $this->tellWurk($ticket, "Reply on {$ticket->reference()} from {$this->business($ticket)}: {$ticket->subject}", $body);
    }

    /** WURK replies; the business is emailed. Solving with the reply asks them to rate it. */
    public function replyFromStaff(SupportTicket $ticket, string $staffId, string $body, bool $solve): void
    {
        $this->addMessage($ticket, 'staff', $staffId, $body, []);
        $ticket->update([
            'status' => $solve ? 'solved' : 'pending', 'solved_at' => $solve ? now() : null, 'last_activity_at' => now(),
            'first_responded_at' => $ticket->first_responded_at ?? now(),
        ]);
        $this->audit->record('support.replied', 'support_ticket', $ticket->id, ['number' => $ticket->number, 'solved' => $solve], ['platform' => true]);
        $this->tellBusiness($ticket, $solve ? "Solved: {$ticket->subject} ({$ticket->reference()})" : "WURK replied: {$ticket->subject} ({$ticket->reference()})",
            [$body, '', $solve ? 'We’ve marked this solved. Reply if it isn’t, or tell us how we did.' : 'Reply from your back office.']);
    }

    public function setStatus(SupportTicket $ticket, string $status, string $staffId): void
    {
        $ticket->update(['status' => $status, 'solved_at' => $status === 'solved' ? now() : null, 'last_activity_at' => now()]);
        $this->audit->record('support.status_changed', 'support_ticket', $ticket->id, ['number' => $ticket->number, 'status' => $status, 'by' => $staffId], ['platform' => true]);
    }

    public function rate(SupportTicket $ticket, string $rating, ?string $comment): void
    {
        $ticket->update(['rating' => $rating, 'rating_comment' => $this->clip($comment, 500)]);
        $this->tellWurk($ticket, "Rated {$rating}: {$ticket->reference()} {$ticket->subject}", (string) $comment);
    }

    /** @param list<UploadedFile> $files */
    private function addMessage(SupportTicket $ticket, string $type, string $by, string $body, array $files): SupportMessage
    {
        $message = SupportMessage::query()->create(['ticket_id' => $ticket->id, 'author_type' => $type, 'author_id' => $by, 'body' => trim($body), 'created_at' => now()]);
        foreach (array_slice($files, 0, 3) as $file) {
            $stored = $this->screenshots->store($ticket->id, $file);
            SupportAttachment::query()->create(['ticket_id' => $ticket->id, 'message_id' => $message->id, ...$stored, 'created_at' => now()]);
        }

        return $message;
    }

    private function tellWurk(SupportTicket $ticket, string $subject, string $body): void
    {
        $to = $this->targets->inboxEmail();
        if ($to !== null) {
            Mail::to($to)->send(new TicketMail($subject, [Str::limit($body, 2000), '', 'Reply in the support inbox:'], route('admin.support.show', ['number' => $ticket->number])));
        }
    }

    /** @param list<string> $lines */
    private function tellBusiness(SupportTicket $ticket, string $subject, array $lines): void
    {
        $email = $this->people->contacts([$ticket->opened_by])[$ticket->opened_by]['email'] ?? null;
        $handle = $this->tenants->find($ticket->tenant_id)?->handle();
        if ($email !== null && $handle !== null) {
            Mail::to($email)->send(new TicketMail($subject, $lines, route('tenant.support.show', ['tenant' => $handle, 'number' => $ticket->number])));
        }
    }

    private function business(SupportTicket $ticket): string
    {
        return $this->tenants->find($ticket->tenant_id)?->displayName() ?? 'a business';
    }

    private function clip(?string $value, int $max): ?string
    {
        $v = $value === null ? '' : trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
