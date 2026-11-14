<?php

declare(strict_types=1);

namespace Wurk\Support\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Contracts\HelpAnswers;
use Wurk\Help\Contracts\HelpSearch;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Support\Models\SupportTicket;
use Wurk\Support\Support\Screenshots;
use Wurk\Support\Support\SupportTargets;
use Wurk\Support\Support\Tickets;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * "Contact support" in a business's back office (Sprint 16.3). Everyone sees the tickets they opened; people with
 * support.tickets (owners) see all of the business's.
 */
final class TicketsController
{
    public function __construct(private readonly TenantContext $context, private readonly Tickets $tickets, private readonly SupportTargets $targets) {}

    public function index(Request $request): Response
    {
        $list = $this->visible($request)->orderByDesc('last_activity_at')->limit(100)
            ->get(['id', 'number', 'subject', 'status', 'rating', 'created_at', 'last_activity_at']);

        return Inertia::render('Support::Tickets', [
            'tickets' => $list->map(fn (SupportTicket $t) => ['number' => $t->number, 'subject' => $t->subject, 'status' => $t->status,
                'rated' => $t->rating !== null, 'opened' => $t->created_at?->toIso8601String(), 'updated' => $t->last_activity_at?->toIso8601String()])->all(),
            'target' => $this->targets->describe($this->targets->forTenant($this->context->id())['hours']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Support::New', [
            'target' => $this->targets->describe($this->targets->forTenant($this->context->id())['hours']),
            'from' => mb_substr((string) $request->query('from', ''), 0, 200),
        ]);
    }

    /** Before a ticket: matching help, and an answer from it (deflection). Free to the business. */
    public function suggest(Request $request, HelpAnswers $answers, HelpSearch $help): JsonResponse
    {
        $q = (string) $request->validate(['question' => ['required', 'string', 'min:5', 'max:5000']])['question'];
        try {
            return new JsonResponse($answers->answer($q, 'customer', $this->context->id()));
        } catch (AiUnavailable) {
            return new JsonResponse(['answer' => null, 'sources' => array_map(fn (array $a) => ['title' => $a['title'], 'url' => $a['url']], $help->relevant($q, 3))]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'page' => ['nullable', 'string', 'max:200'],
            'last_error' => ['nullable', 'string', 'max:500'],
            'screenshots' => ['array', 'max:3'],
            'screenshots.*' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ], ['screenshots.max' => 'Attach up to 3 screenshots.', 'screenshots.*.mimes' => 'Screenshots must be PNG, JPG or WebP.', 'screenshots.*.max' => 'Each screenshot can be up to 5 MB.']);
        $ticket = $this->tickets->open($this->context->id(), (string) $request->user()?->getAuthIdentifier(), $d['subject'], $d['body'],
            ['page' => $d['page'] ?? null, 'browser' => $request->userAgent(), 'last_error' => $d['last_error'] ?? null], array_values($request->file('screenshots', [])));

        return redirect()->route('tenant.support.show', ['number' => $ticket->number])->with('status', "Sent. Your ticket is {$ticket->reference()} — we’ll email you when we reply.");
    }

    public function show(Request $request, PeopleDirectory $people, int $number): Response
    {
        $ticket = $this->find($request, $number);
        $messages = $ticket->messages()->where('internal', false)->get();
        $names = $people->names(array_values(array_unique($messages->where('author_type', 'business')->pluck('author_id')->all())));
        $files = $ticket->attachments()->get()->groupBy('message_id');

        return Inertia::render('Support::Ticket', [
            'ticket' => ['number' => $ticket->number, 'subject' => $ticket->subject, 'status' => $ticket->status, 'rating' => $ticket->rating,
                'opened' => $ticket->created_at?->toIso8601String(), 'due' => $ticket->first_responded_at === null ? $ticket->first_response_due_at->toIso8601String() : null],
            'messages' => $messages->map(fn ($m) => ['id' => $m->id, 'from' => $m->author_type === 'staff' ? 'WURK support' : ($names[$m->author_id] ?? 'You'), 'staff' => $m->author_type === 'staff',
                'body' => $m->body, 'at' => $m->created_at?->toIso8601String(),
                'files' => ($files[$m->id] ?? collect())->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->values()->all()])->all(),
        ]);
    }

    public function reply(Request $request, int $number): RedirectResponse
    {
        $ticket = $this->find($request, $number);
        $d = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000'], 'screenshots' => ['array', 'max:3'], 'screenshots.*' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        $this->tickets->replyFromBusiness($ticket, (string) $request->user()?->getAuthIdentifier(), $d['body'], array_values($request->file('screenshots', [])));

        return back()->with('status', 'Sent.');
    }

    public function rate(Request $request, int $number): RedirectResponse
    {
        $ticket = $this->find($request, $number);
        abort_unless($ticket->status === 'solved', 422, 'You can rate a ticket once it’s solved.');
        $d = $request->validate(['rating' => ['required', 'in:good,bad'], 'comment' => ['nullable', 'string', 'max:500']]);
        $this->tickets->rate($ticket, $d['rating'], $d['comment'] ?? null);

        return back()->with('status', 'Thanks for telling us.');
    }

    public function file(Request $request, int $number, string $file): StreamedResponse
    {
        $ticket = $this->find($request, $number);
        $a = $ticket->attachments()->whereKey($file)->firstOrFail();

        return Storage::disk(Screenshots::DISK)->download($a->path, $a->name, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return Builder<SupportTicket> */
    private function visible(Request $request): Builder
    {
        $me = (string) $request->user()?->getAuthIdentifier();

        return SupportTicket::query()->where('tenant_id', $this->context->id())
            ->when(! ($request->user()?->can('support.tickets') ?? false), fn ($q) => $q->where('opened_by', $me));
    }

    private function find(Request $request, int $number): SupportTicket
    {
        return $this->visible($request)->where('number', $number)->firstOrFail();
    }
}
