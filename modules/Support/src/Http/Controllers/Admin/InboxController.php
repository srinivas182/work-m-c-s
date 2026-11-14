<?php

declare(strict_types=1);

namespace Wurk\Support\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Support\Models\SupportTicket;
use Wurk\Support\Support\Screenshots;
use Wurk\Support\Support\Tickets;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** WURK's support inbox (Sprint 16.3): every business's tickets, oldest due first; reply, solve, reopen. */
final class InboxController
{
    public const STATUSES = ['open' => 'Waiting on WURK', 'pending' => 'Waiting on the business', 'solved' => 'Solved'];

    public function __construct(private readonly Tickets $tickets, private readonly TenantDirectory $tenants) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');
        $status = array_key_exists($status, self::STATUSES) ? $status : 'open';
        $list = SupportTicket::query()->where('status', $status)
            ->when($status === 'solved', fn ($q) => $q->orderByDesc('solved_at'), fn ($q) => $q->orderBy('first_response_due_at'))
            ->limit(200)->get();
        $counts = SupportTicket::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Support::Admin/Inbox', [
            'status' => $status, 'statuses' => self::STATUSES, 'counts' => array_map('intval', $counts->all()),
            'tickets' => $list->map(fn (SupportTicket $t) => ['number' => $t->number, 'subject' => $t->subject, 'business' => $this->tenants->find($t->tenant_id)?->displayName() ?? 'Unknown',
                'package' => $t->package_key, 'target_hours' => $t->target_hours, 'due' => $t->first_responded_at === null ? $t->first_response_due_at->toIso8601String() : null,
                'late' => $t->first_responded_at === null && $t->first_response_due_at->isPast(), 'rating' => $t->rating,
                'updated' => $t->last_activity_at?->toIso8601String()])->all(),
        ]);
    }

    public function show(PeopleDirectory $people, int $number): Response
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $messages = $t->messages()->get();
        $names = $people->names(array_values(array_unique([...$messages->pluck('author_id')->all(), $t->opened_by])));
        $contact = $people->contacts([$t->opened_by])[$t->opened_by] ?? null;
        $tenant = $this->tenants->find($t->tenant_id);
        $files = $t->attachments()->get()->groupBy('message_id');

        return Inertia::render('Support::Admin/Ticket', [
            'ticket' => ['number' => $t->number, 'subject' => $t->subject, 'status' => $t->status, 'statuses' => self::STATUSES,
                'business' => $tenant?->displayName() ?? 'Unknown', 'handle' => $tenant?->handle(), 'package' => $t->package_key, 'target_hours' => $t->target_hours,
                'due' => $t->first_response_due_at->toIso8601String(), 'responded' => $t->first_responded_at?->toIso8601String(),
                'late' => $t->first_responded_at === null ? $t->first_response_due_at->isPast() : $t->first_responded_at->gt($t->first_response_due_at),
                'page' => $t->page, 'browser' => $t->browser, 'last_error' => $t->last_error,
                'rating' => $t->rating, 'rating_comment' => $t->rating_comment,
                'opener' => ['name' => $names[$t->opened_by] ?? 'Unknown', 'email' => $contact['email'] ?? null, 'phone' => $contact['phone'] ?? null]],
            'messages' => $messages->map(fn ($m) => ['id' => $m->id, 'from' => $names[$m->author_id] ?? ($m->author_type === 'staff' ? 'WURK' : 'Business'), 'staff' => $m->author_type === 'staff',
                'internal' => $m->internal, 'body' => $m->body, 'at' => $m->created_at?->toIso8601String(),
                'files' => ($files[$m->id] ?? collect())->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->values()->all()])->all(),
        ]);
    }

    public function reply(Request $request, int $number): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $d = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000'], 'solve' => ['boolean']]);
        $this->tickets->replyFromStaff($t, (string) $request->user()?->getAuthIdentifier(), $d['body'], (bool) ($d['solve'] ?? false));

        return back()->with('status', ($d['solve'] ?? false) ? 'Replied and solved.' : 'Replied.');
    }

    public function status(Request $request, int $number): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $d = $request->validate(['status' => ['required', 'in:'.implode(',', array_keys(self::STATUSES))]]);
        $this->tickets->setStatus($t, $d['status'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Now: '.self::STATUSES[$d['status']].'.');
    }

    public function file(int $number, string $file): StreamedResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $a = $t->attachments()->whereKey($file)->firstOrFail();

        return Storage::disk(Screenshots::DISK)->download($a->path, $a->name, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
    }
}
