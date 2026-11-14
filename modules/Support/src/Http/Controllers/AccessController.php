<?php

declare(strict_types=1);

namespace Wurk\Support\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Support\Models\SupportAccessGrant;
use Wurk\Support\Models\SupportTicket;
use Wurk\Support\Support\SupportAccess;
use Wurk\Tenancy\Contracts\TenantContext;

/** Support → Access (Sprint 16.4): the business decides whether WURK support may come in, how far, and for how long. */
final class AccessController
{
    public function __construct(private readonly TenantContext $context, private readonly SupportAccess $access) {}

    public function show(PeopleDirectory $people): Response
    {
        $grants = SupportAccessGrant::query()->where('tenant_id', $this->context->id())->latest()->limit(20)->get();
        $names = $people->names(array_values(array_unique($grants->pluck('staff_id')->all())));
        $tickets = SupportTicket::query()->whereIn('id', $grants->pluck('ticket_id'))->pluck('number', 'id');
        $tz = $this->context->current()->timezone();

        return Inertia::render('Support::Access', [
            'minutes' => SupportAccess::MINUTES,
            'grants' => $grants->map(fn (SupportAccessGrant $g) => [
                'id' => $g->id, 'staff' => $names[$g->staff_id] ?? 'WURK support', 'ticket' => $tickets[$g->ticket_id] ?? null, 'reason' => $g->reason,
                'requested_level' => $g->requested_level, 'level' => $g->level, 'minutes' => $g->minutes,
                'status' => $g->status === 'active' && ! $g->isLive() ? 'ended' : $g->status,
                'ends_at' => $g->ends_at?->setTimezone($tz)->toIso8601String(), 'asked_at' => $g->created_at?->setTimezone($tz)->toIso8601String(),
            ])->all(),
        ]);
    }

    public function approve(Request $request, string $grant): RedirectResponse
    {
        $g = $this->find($grant, 'requested');
        $d = $request->validate(['level' => ['required', 'in:view,full'], 'minutes' => ['required', 'integer', 'in:'.implode(',', SupportAccess::MINUTES)]]);
        $this->access->approve($g, (string) $request->user()?->getAuthIdentifier(), $d['level'], (int) $d['minutes']);

        return back()->with('status', 'Approved: '.($d['level'] === 'full' ? 'full' : 'view-only')." access for {$d['minutes']} minutes. You can end it at any time.");
    }

    public function decline(Request $request, string $grant): RedirectResponse
    {
        $this->access->decline($this->find($grant, 'requested'), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Declined. WURK support can’t see your back office.');
    }

    public function end(Request $request, string $grant): RedirectResponse
    {
        $this->access->end($this->find($grant, 'active'), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Ended. WURK support no longer has access.');
    }

    private function find(string $id, string $status): SupportAccessGrant
    {
        return SupportAccessGrant::query()->where('tenant_id', $this->context->id())->where('status', $status)->findOrFail($id);
    }
}
