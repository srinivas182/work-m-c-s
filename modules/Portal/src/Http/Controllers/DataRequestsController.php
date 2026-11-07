<?php

declare(strict_types=1);

namespace Wurk\Portal\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Portal\Support\DataRequest;
use Wurk\Portal\Support\DataRequests;
use Wurk\Referrals\Contracts\CustomerRewards;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Data requests: POPIA deletion requests from the portal (Sprint 13.6e). */
final class DataRequestsController
{
    public function __construct(private TenantContext $context, private DataRequests $requests) {}

    public function index(): Response
    {
        $rows = DB::connection($this->context->connectionName())->table('data_requests')->where('tenant_id', $this->context->id())
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->orderByDesc('created_at')->limit(200)->get();
        $names = app(ContactBook::class)->names(array_values(array_unique(array_map(fn ($r) => (string) $r->contact_id, $rows->all()))));
        $tz = $this->context->current()->timezone();

        return Inertia::render('Portal::DataRequests', ['requests' => $rows->map(function ($r) use ($names, $tz) {
            $asked = CarbonImmutable::parse((string) $r->created_at);
            $open = $r->status === 'open';
            $rewards = $open ? app(CustomerRewards::class)->forContact((string) $r->contact_id) : null;

            return ['id' => (string) $r->id, 'customer' => $names[(string) $r->contact_id] ?? 'Customer', 'status' => (string) $r->status, 'reason' => $r->reason,
                'asked' => $asked->setTimezone($tz)->format('j M Y'), 'days_open' => $open ? (int) $asked->diffInDays(now()) : null,
                'wallet_cents' => $open ? app(StoreCredit::class)->available((string) $r->contact_id) : 0, 'pending_cents' => $rewards['pending_cents'] ?? 0,
                'decided' => $r->decided_at === null ? null : CarbonImmutable::parse((string) $r->decided_at)->setTimezone($tz)->format('j M Y').' by '.$r->decided_by_name];
        })->all()]);
    }

    public function settle(Request $request, string $id): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'method' => ['required', 'in:eft,cash'], 'reference' => ['nullable', 'string', 'max:80']]);
        $paid = $this->requests->settle($this->request($id), (int) round((float) $d['amount'] * 100), $d['method'] === 'eft' ? 'EFT' : 'Cash', (string) ($d['reference'] ?? ''));

        return back()->with('status', 'Recorded: R'.number_format($paid / 100, 2).' paid to the customer.');
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        return $this->act(fn () => $this->requests->approve($this->request($id), $this->who($request)), 'Done — their information has been deleted and they’ve been told.');
    }

    public function decline(Request $request, string $id): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the customer why.'])['reason'];

        return $this->act(fn () => $this->requests->decline($this->request($id), $this->who($request), $reason), 'Declined — the customer has been told why.');
    }

    private function act(\Closure $fn, string $done): RedirectResponse
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['request' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }

    private function request(string $id): DataRequest
    {
        return $this->requests->find($id) ?? abort(404);
    }

    private function who(Request $request): string
    {
        return trim((string) data_get($request->user(), 'first_name').' '.(string) data_get($request->user(), 'last_name')) ?: 'Team';
    }
}
