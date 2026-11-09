<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Campaigns\Support\CampaignSender;
use Wurk\Campaigns\Support\ReadyMade;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Campaigns (Sprint 15.3a). */
final class CampaignsController
{
    public const SEGMENTS = ['all' => 'Everyone who agreed to hear from you', 'recent' => 'Seen in the last 30 days', 'inactive' => 'Not seen in 60+ days', 'branch' => 'Customers of one branch'];

    public function __construct(private TenantContext $context, private CampaignSender $sender) {}

    public function index(): Response
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $stats = $db->table('campaign_messages')->where('tenant_id', $t)->selectRaw("campaign_id, count(*) as total, sum(case when status = 'sent' then 1 else 0 end) as sent, count(opened_at) as opened, count(clicked_at) as clicked")
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');

        return Inertia::render('Campaigns::Index', [
            'campaigns' => $db->table('campaigns')->where('tenant_id', $t)->orderByDesc('created_at')->limit(50)->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'channel' => $c->channel, 'status' => $c->status, 'scheduled_at' => $c->scheduled_at, 'sent_at' => $c->sent_at,
                'sent' => (int) ($stats[$c->id]->sent ?? 0), 'failed' => (int) ($stats[$c->id]->total ?? 0) - (int) ($stats[$c->id]->sent ?? 0),
                'opened' => (int) ($stats[$c->id]->opened ?? 0), 'clicked' => (int) ($stats[$c->id]->clicked ?? 0),
            ])->all(),
            'readyMade' => ReadyMade::all(),
        ]);
    }

    public function create(Request $request, BranchDirectory $branches): Response
    {
        $ready = ReadyMade::all()[(string) $request->query('ready', '')] ?? null;

        return Inertia::render('Campaigns::Compose', ['segments' => self::SEGMENTS, 'branches' => $branches->several() ? array_map(fn ($b) => ['id' => $b['id'], 'name' => $b['name']], $branches->all()) : [],
            'draft' => $ready]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $d = $this->validateAudience($request);

        return response()->json($this->sender->estimate($d['channel'], $d['segment'], $d['branch_id'] ?? null));
    }

    public function store(Request $request): RedirectResponse
    {
        $d = [...$this->validateAudience($request), ...$request->validate([
            'name' => ['required', 'string', 'max:120'], 'subject' => ['nullable', 'required_if:channel,email', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:'.($request->input('channel') === 'sms' ? 300 : 10000)],
        ], ['subject.required_if' => 'An email needs a subject.', 'body.max' => 'SMS messages are kept to 300 characters.'])];
        $id = (string) Str::ulid();
        DB::connection($this->context->connectionName())->table('campaigns')->insert(['id' => $id, 'tenant_id' => $this->context->id(), 'name' => $d['name'], 'channel' => $d['channel'],
            'segment' => $d['segment'], 'branch_id' => $d['branch_id'] ?? null, 'subject' => $d['subject'] ?? null, 'body' => $d['body'], 'status' => 'draft',
            'created_by' => (string) Auth::id(), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->to(url()->current().'/'.$id)->with('status', 'Saved. Send yourself a test, then send it.');
    }

    public function show(string $campaign): Response
    {
        $c = $this->find($campaign);

        return Inertia::render('Campaigns::Show', ['campaign' => (array) $c, 'segment' => self::SEGMENTS[$c->segment] ?? $c->segment,
            'estimate' => in_array($c->status, ['draft', 'scheduled'], true) ? $this->sender->estimate((string) $c->channel, (string) $c->segment, $c->branch_id) : null]);
    }

    public function test(string $campaign, PeopleDirectory $people): RedirectResponse
    {
        $c = $this->find($campaign);
        $me = $people->contacts([(string) Auth::id()])[(string) Auth::id()] ?? null;
        $to = $c->channel === 'sms' ? ($me['phone'] ?? null) : ($me['email'] ?? null);
        if ($to === null) {
            throw ValidationException::withMessages(['send' => 'We have no '.($c->channel === 'sms' ? 'phone number' : 'email').' for you to send the test to.']);
        }
        $this->act(fn () => $this->sender->test($campaign, (string) $to, (string) ($me['name'] ?? 'Test')));

        return back()->with('status', 'Test sent to '.$to.'.');
    }

    public function send(Request $request, string $campaign): RedirectResponse
    {
        $d = $request->validate(['when' => ['required', Rule::in(['now', 'later'])], 'at' => ['nullable', 'required_if:when,later', 'date', 'after:now']], ['at.after' => 'Pick a time in the future.']);
        $c = $this->find($campaign);
        if ($d['when'] === 'later') {
            if (! in_array($c->status, ['draft', 'scheduled'], true)) {
                throw ValidationException::withMessages(['send' => 'This campaign has already been sent.']);
            }
            DB::connection($this->context->connectionName())->table('campaigns')->where('tenant_id', $this->context->id())->where('id', $c->id)->whereIn('status', ['draft', 'scheduled'])
                ->update(['status' => 'scheduled', 'scheduled_at' => CarbonImmutable::parse((string) $d['at'], $this->context->current()->timezone())->setTimezone((string) config('app.timezone')), 'updated_at' => now()]);

            return back()->with('status', 'Scheduled.');
        }
        $sent = 0;
        $this->act(function () use ($campaign, &$sent) {
            $sent = $this->sender->send($campaign);
        });

        return back()->with('status', "Sent to {$sent} ".($sent === 1 ? 'customer' : 'customers').'.');
    }

    /** @return array{channel: string, segment: string, branch_id?: string|null} */
    private function validateAudience(Request $request): array
    {
        /** @var array{channel: string, segment: string, branch_id?: string|null} */
        return $request->validate(['channel' => ['required', Rule::in(['email', 'sms'])], 'segment' => ['required', Rule::in(array_keys(self::SEGMENTS))],
            'branch_id' => ['nullable', 'required_if:segment,branch', 'string', 'max:26']]);
    }

    private function find(string $id): \stdClass
    {
        return DB::connection($this->context->connectionName())->table('campaigns')->where('tenant_id', $this->context->id())->where('id', $id)->first() ?? abort(404);
    }

    private function act(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['send' => $e->getMessage()]);
        }
    }
}
