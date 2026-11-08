<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentBanking;
use Wurk\Agents\Support\AgentCommission;

/** The agent area at app.wurk.co.za/agent (Sprint 14.7 starts it; 14.6 completes it). Agents only. */
final class AgentAreaController
{
    public const BANKS = ['ABSA', 'African Bank', 'Bidvest Bank', 'Capitec', 'Discovery Bank', 'FNB', 'Investec', 'Nedbank', 'Standard Bank', 'TymeBank', 'Other'];

    public function __construct(private AgentBanking $banking) {}

    public function home(): Response
    {
        $agent = $this->agent();

        return Inertia::render('Agents::Agent/Home', ['code' => $agent->code, 'link' => route('agents.link', ['code' => $agent->code]),
            'status' => $agent->status, 'commission' => app(AgentCommission::class)->balance($agent->id), 'hasBank' => $this->banking->current($agent) !== null]);
    }

    public function bank(Request $request): Response
    {
        $agent = $this->agent();

        return Inertia::render('Agents::Agent/Bank', ['current' => $this->banking->current($agent), 'banks' => self::BANKS,
            'sent' => $this->banking->hasPending($request) ? $request->session()->get('agent.bank_sent') : null]);
    }

    public function request(Request $request): RedirectResponse
    {
        $agent = $this->agent();
        $d = $request->validate([
            'holder' => ['required', 'string', 'max:120'], 'bank' => ['required', Rule::in(self::BANKS)],
            'branch_code' => ['required', 'regex:/^\d{6}$/'], 'account_type' => ['required', Rule::in(['cheque', 'savings', 'transmission'])],
            'account' => ['required', 'regex:/^[\d ]{6,20}$/'],
        ], ['branch_code.regex' => 'Branch codes have 6 digits.', 'account.regex' => 'Account numbers are 6 to 20 digits.']);
        try {
            $masked = $this->banking->request($request, $agent, $d);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }
        $request->session()->put('agent.bank_sent', $masked);

        return back()->with('status', "We sent a code to {$masked}. Enter it to save your banking details.");
    }

    public function confirm(Request $request): RedirectResponse
    {
        $agent = $this->agent();
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        try {
            $this->banking->confirm($request, $agent, $code);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }
        $request->session()->forget('agent.bank_sent');

        return redirect('/agent/bank')->with('status', 'Banking details saved.');
    }

    private function agent(): Agent
    {
        return Agent::query()->where('identity_id', (string) Auth::id())->first() ?? abort(404); // agents only
    }
}
