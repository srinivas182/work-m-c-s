<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Agents\Support\AgentPayouts;

/** Admin → Agent payouts (Sprint 14.5a): WURK finance builds, downloads and cancels EFT batches. */
final class PayoutsController
{
    public function __construct(private AgentPayouts $payouts) {}

    public function index(): Response
    {
        Gate::authorize('agents.pay');

        return Inertia::render('Agents::Admin/Payouts', [
            'minimum' => $this->payouts->minimum(), 'preview' => $this->payouts->preview(),
            'batches' => DB::table('agent_payout_batches')->orderByDesc('created_at')->limit(50)->get(['id', 'reference', 'status', 'count', 'total_cents', 'created_at', 'paid_at'])
                ->map(fn ($b) => ['id' => $b->id, 'reference' => $b->reference, 'status' => $b->status, 'count' => (int) $b->count, 'total_cents' => (int) $b->total_cents, 'created' => substr((string) $b->created_at, 0, 10)])->all(),
        ]);
    }

    public function minimum(Request $request): RedirectResponse
    {
        Gate::authorize('agents.pay');
        $rand = (float) $request->validate(['minimum' => ['required', 'numeric', 'min:0', 'max:100000']])['minimum'];
        $this->payouts->setMinimum((int) round($rand * 100));

        return back()->with('status', 'Minimum payout saved.');
    }

    public function store(): RedirectResponse
    {
        Gate::authorize('agents.pay');
        try {
            $this->payouts->create((string) Auth::id());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['batch' => $e->getMessage()]);
        }

        return back()->with('status', 'Batch created. Download the file for your bank, pay it, then mark it paid.');
    }

    public function csv(string $batch): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('agents.pay');
        $reference = DB::table('agent_payout_batches')->where('id', $batch)->value('reference') ?? abort(404);
        try {
            $csv = $this->payouts->csv($batch, (string) Auth::id());
        } catch (\DomainException $e) {
            return back()->withErrors(['batch' => $e->getMessage()]);
        }

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$reference.'.csv"', 'Cache-Control' => 'no-store']);
    }

    public function cancel(string $batch): RedirectResponse
    {
        Gate::authorize('agents.pay');
        try {
            $this->payouts->cancel($batch);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['batch' => $e->getMessage()]);
        }

        return back()->with('status', 'Batch cancelled. The amounts are back in the agents’ balances.');
    }
}
