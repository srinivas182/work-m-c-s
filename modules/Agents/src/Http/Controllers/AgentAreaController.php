<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentBanking;
use Wurk\Agents\Support\AgentDashboard;
use Wurk\Agents\Support\AgentDemos;
use Wurk\Website\Contracts\SiteTemplates;

/** The agent area at app.wurk.co.za/agent (Sprint 14.7 starts it; 14.6 completes it). Agents only. */
final class AgentAreaController
{
    public const BANKS = ['ABSA', 'African Bank', 'Bidvest Bank', 'Capitec', 'Discovery Bank', 'FNB', 'Investec', 'Nedbank', 'Standard Bank', 'TymeBank', 'Other'];

    public function __construct(private AgentBanking $banking) {}

    public function home(): Response
    {
        $agent = $this->agent();

        return Inertia::render('Agents::Agent/Home', ['code' => $agent->code, 'link' => route('agents.link', ['code' => $agent->code]),
            'status' => $agent->status, 'hasBank' => $this->banking->current($agent) !== null, ...app(AgentDashboard::class)->summary($agent)]);
    }

    public function businesses(): Response
    {
        return Inertia::render('Agents::Agent/Businesses', ['businesses' => app(AgentDashboard::class)->businesses($this->agent())]);
    }

    public const PERIODS = ['month' => 'This month', '30d' => 'Last 30 days', 'year' => 'This year', 'all' => 'All time'];

    public function earnings(Request $request): Response|\Symfony\Component\HttpFoundation\Response
    {
        $agent = $this->agent();
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'month';
        $now = CarbonImmutable::now();
        $from = match ($period) {
            '30d' => $now->subDays(30), 'year' => $now->startOfYear(), 'all' => CarbonImmutable::parse('2000-01-01'), default => $now->startOfMonth()
        };
        $lines = app(AgentDashboard::class)->earnings($agent, $from, $now);
        if ($request->query('format') === 'csv') { // for the agent's own records
            $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Could not build the file.');
            fputcsv($out, ['Date', 'Business', 'Level', 'Type', 'Amount', 'Payable from']);
            foreach ($lines as $l) {
                fputcsv($out, [$l['at'], preg_match('/^[=+\-@]/', $l['business']) === 1 ? "'".$l['business'] : $l['business'], $l['level'] === 0 ? 'Own' : 'Level '.$l['level'],
                    $l['kind'] === 'earned' ? 'Earned' : 'Reversed', number_format($l['cents'] / 100, 2, '.', ''), $l['payable']]);
            }
            rewind($out);

            return response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="wurk-earnings-'.$period.'.csv"']);
        }

        return Inertia::render('Agents::Agent/Earnings', ['period' => $period, 'periods' => self::PERIODS, 'lines' => $lines]);
    }

    public function team(): Response
    {
        return Inertia::render('Agents::Agent/Team', ['team' => app(AgentDashboard::class)->team($this->agent())]);
    }

    public function payouts(): Response
    {
        return Inertia::render('Agents::Agent/Payouts', ['payouts' => app(AgentDashboard::class)->payouts($this->agent())]);
    }

    public function kit(): Response
    {
        $agent = $this->agent();
        $link = route('agents.link', ['code' => $agent->code]);
        $message = "I use WURK to run my business online — website, bookings, a shop and more, in one place. Have a look: {$link}";

        return Inertia::render('Agents::Agent/Kit', ['link' => $link, 'code' => $agent->code, 'message' => $message, 'whatsapp' => 'https://wa.me/?text='.rawurlencode($message)]);
    }

    public function qr(): \Symfony\Component\HttpFoundation\Response
    {
        $agent = $this->agent();
        $svg = (new Writer(new ImageRenderer(new RendererStyle(480, 2), new SvgImageBackEnd)))
            ->writeString(route('agents.link', ['code' => $agent->code]));

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'attachment; filename="wurk-'.$agent->code.'.svg"']);
    }

    public function demo(): Response
    {
        $agent = $this->agent();
        $demo = app(AgentDemos::class)->active($agent);

        return Inertia::render('Agents::Agent/Demo', [
            'demo' => $demo === null ? null : $demo + ['site' => request()->getScheme().'://'.$demo['handle'].'.'.config('wurk.domains.root'), 'backOffice' => '/'.$demo['handle']],
            'catalogue' => app(SiteTemplates::class)->catalogue(), 'days' => AgentDemos::DAYS,
        ]);
    }

    public function createDemo(Request $request): RedirectResponse
    {
        $d = $request->validate(['business' => ['required', 'string', 'max:80'], 'category' => ['required', 'string', 'max:60'], 'template' => ['required', 'string', 'max:60']],
            ['business.required' => 'The business’s name.', 'template.required' => 'Choose a template.']);
        try {
            app(AgentDemos::class)->create($this->agent(), $d['business'], $d['category'], $d['template']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['template' => $e->getMessage()]);
        }

        return redirect('/agent/demo')->with('status', 'Your demo is live. It closes in '.AgentDemos::DAYS.' days.');
    }

    public function deleteDemo(): RedirectResponse
    {
        try {
            app(AgentDemos::class)->delete($this->agent());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['template' => $e->getMessage()]);
        }

        return redirect('/agent/demo')->with('status', 'Demo deleted. You can make another.');
    }

    public function demoQr(): \Symfony\Component\HttpFoundation\Response
    {
        $demo = app(AgentDemos::class)->active($this->agent()) ?? abort(404);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(480, 2), new SvgImageBackEnd)))
            ->writeString(request()->getScheme().'://'.$demo['handle'].'.'.config('wurk.domains.root'));

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'inline; filename="demo.svg"']);
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
