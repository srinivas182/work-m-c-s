<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentDemos;
use Wurk\Agents\Support\AgentOnboarding;
use Wurk\Billing\Contracts\CreditTopUps;
use Wurk\Plans\Contracts\Catalog;

/** Agent area → Set up a business (23.4): the owner's number, a package, setup, the first top-up and hand-over. */
final class AgentOnboardingController
{
    public function __construct(private AgentOnboarding $onboarding) {}

    public function index(AgentDemos $demos): Response
    {
        $agent = $this->agent();

        return Inertia::render('Agents::Agent/Onboard', ['onboarding' => null, 'recent' => $this->onboarding->recent($agent),
            'demo' => ($d = $demos->active($agent)) === null ? null : ['business' => $d['business'], 'handle' => $d['handle']]]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:120'], 'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'], 'mobile' => ['required', 'string', 'max:30'], 'use_demo' => ['boolean'],
        ]);
        $id = $this->onboarding->start($this->agent(), $data, $request->ip());

        return redirect()->route('agent.onboard.show', ['onboarding' => $id]);
    }

    public function show(string $onboarding, Catalog $catalog, CreditTopUps $topUps): Response
    {
        $agent = $this->agent();
        $o = $this->onboarding->find($agent, $onboarding);
        $d = $o->data;

        return Inertia::render('Agents::Agent/Onboard', [
            'onboarding' => ['id' => $o->id, 'step' => $o->step, 'business' => $d['business_name'], 'owner' => $d['first_name'], 'masked' => $d['masked'],
                'handle' => $d['handle'] ?? null, 'demo' => is_string($d['demo_tenant_id'] ?? null), 'top_up' => $d['top_up'] ?? null,
                'setup_url' => isset($d['handle']) ? '/'.$d['handle'].'/website/set-up?return='.rawurlencode(route('agent.onboard.show', ['onboarding' => $o->id], false)) : null,
                'started' => (string) $o->created_at],
            'packages' => $o->step === 'package' ? array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'],
                'monthly_cents' => $p['monthly_cents'], 'yearly_cents' => $p['yearly_cents'], 'trial_days' => $p['trial_days']], $catalog->onSale()) : [],
            'methods' => $o->step === 'topup' ? $topUps->methods() : [],
            'minTopUp' => CreditTopUps::MIN_RAND,
            'recent' => [], 'demo' => null,
        ]);
    }

    public function resend(Request $request, string $onboarding): RedirectResponse
    {
        $this->onboarding->resend($this->onboarding->find($this->agent(), $onboarding), $request->ip());

        return back()->with('status', 'We sent a new code.');
    }

    public function verify(Request $request, string $onboarding): RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];
        $this->onboarding->verify($this->onboarding->find($this->agent(), $onboarding), $code);

        return back();
    }

    public function package(Request $request, string $onboarding): RedirectResponse
    {
        $data = $request->validate(['package_id' => ['required', 'string'], 'period' => ['required', 'in:monthly,yearly']]);
        $agent = $this->agent();
        $this->onboarding->choosePackage($agent, $this->onboarding->find($agent, $onboarding), $data['package_id'], $data['period']);

        return back();
    }

    public function setupDone(string $onboarding): RedirectResponse
    {
        $this->onboarding->setupDone($this->onboarding->find($this->agent(), $onboarding));

        return back();
    }

    public function topUp(Request $request, string $onboarding): RedirectResponse|HttpResponse
    {
        $agent = $this->agent();
        $o = $this->onboarding->find($agent, $onboarding);
        $data = $request->validate([
            'how' => ['required', 'in:online,cash,later'],
            'amount' => ['required_unless:how,later', 'nullable', 'integer', 'min:'.CreditTopUps::MIN_RAND, 'max:5000'],
            'email' => ['required_if:how,online', 'nullable', 'email:rfc', 'max:255'],
            'method' => ['nullable', 'string', 'max:40'],
            'receipt' => ['required_if:how,cash', 'nullable', 'string', 'max:40'],
        ], ['amount.min' => 'Top up at least R'.CreditTopUps::MIN_RAND.'.', 'email.required_if' => 'Add the owner’s email for the receipt.', 'receipt.required_if' => 'Write the receipt number you gave the owner.']);

        return match ($data['how']) {
            'online' => Inertia::location($this->onboarding->topUpOnline($o, (int) $data['amount'], (string) $data['email'], $data['method'] ?? null,
                route('agent.onboard.paid', ['onboarding' => $o->id]))),
            'cash' => tap(back()->with('status', 'Cash recorded. It counts once WURK confirms it.'), fn () => $this->onboarding->topUpCash($agent, $o, (int) $data['amount'], (string) $data['receipt'])),
            default => tap(back(), fn () => $this->onboarding->skipTopUp($o)),
        };
    }

    public function paid(Request $request, string $onboarding): RedirectResponse
    {
        $o = $this->onboarding->find($this->agent(), $onboarding);
        $status = $this->onboarding->topUpReturned($o, (string) $request->query('reference', ''));

        return redirect()->route('agent.onboard.show', ['onboarding' => $o->id])->with($status === 'failed' ? 'warning' : 'status', match ($status) {
            'succeeded' => 'Paid — the credits are ready.',
            'failed' => 'The payment didn’t go through. Try again, take cash, or let the owner top up later.',
            default => 'Waiting for the bank to confirm. Refresh in a minute.',
        });
    }

    public function handOver(string $onboarding): RedirectResponse
    {
        $agent = $this->agent();
        $minutes = $this->onboarding->handOver($agent, $this->onboarding->find($agent, $onboarding));

        return back()->with('status', "Handed over in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'. The owner has their sign-in link on WhatsApp.');
    }

    private function agent(): Agent
    {
        return Agent::query()->where('identity_id', (string) Auth::id())->first() ?? abort(404); // agents only
    }
}
