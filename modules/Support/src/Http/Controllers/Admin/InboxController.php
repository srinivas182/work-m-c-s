<?php

declare(strict_types=1);

namespace Wurk\Support\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Contracts\HelpAnswers;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Support\Models\SupportCannedReply;
use Wurk\Support\Models\SupportTicket;
use Wurk\Support\Support\Screenshots;
use Wurk\Support\Support\SupportAccess;
use Wurk\Support\Support\SupportTeam;
use Wurk\Support\Support\Tickets;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * WURK's support inbox (Sprint 16.3): every business's tickets — urgent first, then soonest due; views (mine, unassigned,
 * late, due soon); assign, priority, internal notes, canned replies and an AI-suggested reply; reply, solve, reopen.
 */
final class InboxController
{
    public const STATUSES = ['open' => 'Waiting on WURK', 'pending' => 'Waiting on the business', 'solved' => 'Solved'];

    public const PRIORITIES = ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];

    public const VIEWS = ['all' => 'Everyone’s', 'mine' => 'Mine', 'unassigned' => 'Unassigned', 'late' => 'First reply late', 'soon' => 'Due within an hour'];

    public function __construct(private readonly Tickets $tickets, private readonly TenantDirectory $tenants, private readonly SupportTeam $team) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');
        $status = array_key_exists($status, self::STATUSES) ? $status : 'open';
        $view = (string) $request->query('view', 'all');
        $view = array_key_exists($view, self::VIEWS) ? $view : 'all';
        $priority = (string) $request->query('priority', '');
        $priority = array_key_exists($priority, self::PRIORITIES) ? $priority : '';
        $me = (string) $request->user()?->getAuthIdentifier();
        $list = SupportTicket::query()->where('status', $status)
            ->when($view === 'mine', fn ($q) => $q->where('assignee_id', $me))
            ->when($view === 'unassigned', fn ($q) => $q->whereNull('assignee_id'))
            ->when($view === 'late', fn ($q) => $q->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))
            ->when($view === 'soon', fn ($q) => $q->whereNull('first_responded_at')->whereBetween('first_response_due_at', [now(), now()->addHour()]))
            ->when($priority !== '', fn ($q) => $q->where('priority', $priority))
            ->when($status === 'solved', fn ($q) => $q->orderByDesc('solved_at'), fn ($q) => $q
                ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->orderBy('first_response_due_at'))
            ->limit(200)->get();
        $counts = SupportTicket::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $names = array_column($this->team->members(), 'name', 'id');

        return Inertia::render('Support::Admin/Inbox', [
            'status' => $status, 'view' => $view, 'priority' => $priority, 'statuses' => self::STATUSES, 'views' => self::VIEWS, 'priorities' => self::PRIORITIES,
            'counts' => array_map('intval', $counts->all()),
            'late' => SupportTicket::query()->whereIn('status', ['open', 'pending'])->whereNull('first_responded_at')->where('first_response_due_at', '<', now())->count(),
            'tickets' => $list->map(fn (SupportTicket $t) => ['number' => $t->number, 'subject' => $t->subject, 'business' => $this->tenants->find($t->tenant_id)?->displayName() ?? 'Unknown',
                'package' => $t->package_key, 'target_hours' => $t->target_hours, 'priority' => $t->priority, 'assignee' => $t->assignee_id === null ? null : ($names[$t->assignee_id] ?? 'Someone'),
                'due' => $t->first_responded_at === null ? $t->first_response_due_at->toIso8601String() : null,
                'late' => $t->first_responded_at === null && $t->first_response_due_at->isPast(), 'rating' => $t->rating,
                'updated' => $t->last_activity_at?->toIso8601String()])->all(),
        ]);
    }

    public function show(Request $request, PeopleDirectory $people, SupportAccess $access, int $number): Response
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $grant = $access->forTicket($t);
        $messages = $t->messages()->get();
        $names = $people->names(array_values(array_unique([...$messages->pluck('author_id')->all(), $t->opened_by])));
        $contact = $people->contacts([$t->opened_by])[$t->opened_by] ?? null;
        $tenant = $this->tenants->find($t->tenant_id);
        $files = $t->attachments()->get()->groupBy('message_id');

        return Inertia::render('Support::Admin/Ticket', [
            'team' => $this->team->members(),
            'access' => $grant === null ? null : ['status' => $grant->status, 'level' => $grant->level ?? $grant->requested_level, 'ends_at' => $grant->ends_at?->toIso8601String(),
                'mine' => $grant->staff_id === (string) $request->user()?->getAuthIdentifier()],
            'priorities' => self::PRIORITIES,
            'canned' => SupportCannedReply::query()->orderByDesc('uses')->orderBy('title')->get(['id', 'title', 'body'])->toArray(),
            'ticket' => ['number' => $t->number, 'subject' => $t->subject, 'status' => $t->status, 'statuses' => self::STATUSES, 'priority' => $t->priority, 'assignee_id' => $t->assignee_id,
                'business' => $tenant?->displayName() ?? 'Unknown', 'handle' => $tenant?->handle(), 'app_domain' => (string) config('wurk.domains.app'), 'package' => $t->package_key, 'target_hours' => $t->target_hours,
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
        $d = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000'], 'solve' => ['boolean'], 'canned_id' => ['nullable', 'string', 'max:26']]);
        $this->tickets->replyFromStaff($t, (string) $request->user()?->getAuthIdentifier(), $d['body'], (bool) ($d['solve'] ?? false));
        if (($d['canned_id'] ?? null) !== null) {
            SupportCannedReply::query()->whereKey($d['canned_id'])->increment('uses'); // most-used first in the picker
        }

        return back()->with('status', ($d['solve'] ?? false) ? 'Replied and solved.' : 'Replied.');
    }

    public function status(Request $request, int $number): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $d = $request->validate(['status' => ['required', 'in:'.implode(',', array_keys(self::STATUSES))]]);
        $this->tickets->setStatus($t, $d['status'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Now: '.self::STATUSES[$d['status']].'.');
    }

    public function assign(Request $request, int $number): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $d = $request->validate(['assignee_id' => ['nullable', 'string', fn (string $a, mixed $v, \Closure $fail) => $v === null || $this->team->has((string) $v) ?: $fail('Choose someone on the support team.')]]);
        $this->tickets->assign($t, $d['assignee_id'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', ($d['assignee_id'] ?? null) === null ? 'Unassigned.' : 'Assigned.');
    }

    public function priority(Request $request, int $number): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $d = $request->validate(['priority' => ['required', 'in:'.implode(',', array_keys(self::PRIORITIES))]]);
        $this->tickets->setPriority($t, $d['priority'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Priority: '.self::PRIORITIES[$d['priority']].'.');
    }

    public function note(Request $request, int $number): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $d = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000']]);
        $this->tickets->note($t, (string) $request->user()?->getAuthIdentifier(), $d['body']);

        return back()->with('status', 'Note added (only WURK sees it).');
    }

    /** A draft reply from the help centre, with the articles it used — for staff to check and edit. */
    public function suggest(int $number, HelpAnswers $answers): JsonResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $thread = $t->messages()->where('internal', false)->get()->map(fn ($m) => ($m->author_type === 'staff' ? 'WURK: ' : 'Business: ').$m->body)->implode("\n\n");
        try {
            $s = $answers->answer("Subject: {$t->subject}\n\n".mb_substr($thread, -4000), 'staff', $t->tenant_id);
        } catch (AiUnavailable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 503);
        }

        return new JsonResponse($s['answer'] === null ? ['answer' => null, 'sources' => $s['sources'], 'message' => 'The help centre doesn’t cover this one — worth an article?'] : $s);
    }

    /** Ask the business for access to its back office (16.4). Nothing happens until an owner approves. */
    public function requestAccess(Request $request, int $number, SupportAccess $access): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $d = $request->validate(['level' => ['required', 'in:view,full'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $access->request($t, (string) $request->user()?->getAuthIdentifier(), $d['level'], $d['reason']);

        return back()->with('status', 'Asked. The business’s owners have been told; you’ll be emailed when they decide.');
    }

    public function endAccess(Request $request, int $number, SupportAccess $access): RedirectResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $g = $access->forTicket($t);
        if ($g !== null && $g->status === 'active' && $g->staff_id === (string) $request->user()?->getAuthIdentifier()) {
            $access->end($g, (string) $request->user()?->getAuthIdentifier(), byWurk: true);
        }

        return back()->with('status', 'Access ended.');
    }

    public function file(int $number, string $file): StreamedResponse
    {
        $t = SupportTicket::query()->where('number', $number)->firstOrFail();
        $a = $t->attachments()->whereKey($file)->firstOrFail();

        return Storage::disk(Screenshots::DISK)->download($a->path, $a->name, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
    }
}
