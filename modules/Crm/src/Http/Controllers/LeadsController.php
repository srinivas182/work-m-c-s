<?php

declare(strict_types=1);

namespace Wurk\Crm\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Crm\Models\Contact;
use Wurk\Crm\Models\Lead;
use Wurk\Crm\Models\Stage;
use Wurk\Crm\Support\Leads;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Leads: the pipeline board, a lead's page, and the pipeline's stages. */
final class LeadsController
{
    public function __construct(private TenantContext $context, private Leads $leads, private TeamDirectory $team) {}

    public function index(Request $request): Response
    {
        $pipeline = $this->leads->pipeline();
        $team = $this->teamNames();
        $tz = $this->context->current()->timezone();
        $closed = $request->query('show') === 'closed'; // open leads by default; won and lost on request
        $rows = $this->visible($request)->where('pipeline_id', $pipeline->id)->when($closed, fn ($q) => $q->whereNotNull('closed_at'), fn ($q) => $q->whereNull('closed_at'))
            ->orderByDesc('updated_at')->limit(500)->get();
        $names = Contact::query()->whereIn('id', $rows->pluck('contact_id'))->pluck('name', 'id');

        return Inertia::render('Crm::Leads', [
            'pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name],
            'stages' => $this->stages($pipeline->id),
            'view' => $request->query('view') === 'list' ? 'list' : 'board',
            'closed' => $closed,
            'leads' => $rows->map(fn (Lead $l) => ['id' => $l->id, 'title' => $l->title, 'contact' => (string) ($names[$l->contact_id] ?? ''), 'stage' => $l->stage_id, 'source' => $l->source,
                'value_cents' => $l->value_cents, 'assignee' => $team[(string) $l->assigned_to] ?? null,
                'follow_up' => $l->follow_up_at?->setTimezone($tz)->format('j M'), 'overdue' => $l->closed_at === null && $l->follow_up_at !== null && $l->follow_up_at->isPast()])->all(),
            'canManage' => Gate::allows('leads.manage'),
        ]);
    }

    public function show(Request $request, string $lead): Response
    {
        $l = $this->visible($request)->whereKey($lead)->firstOrFail();
        $c = Contact::query()->find($l->contact_id);
        $tz = $this->context->current()->timezone();

        return Inertia::render('Crm::Lead', [
            'lead' => ['id' => $l->id, 'title' => $l->title, 'stage' => $l->stage_id, 'value_cents' => $l->value_cents, 'source' => $l->source,
                'assigned_to' => $l->assigned_to, 'follow_up' => $l->follow_up_at?->setTimezone($tz)->format('Y-m-d\TH:i'), 'closed' => $l->closed_at !== null,
                'contact' => $c === null ? null : ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email]],
            'stages' => $this->stages($l->pipeline_id),
            'team' => array_map(fn ($id, $name) => ['id' => (string) $id, 'name' => $name], array_keys($this->teamNames()), $this->teamNames()),
            'history' => DB::connection($this->context->connectionName())->table('lead_events')->where('lead_id', $l->id)->orderByDesc('at')->orderByDesc('id')->limit(200)->get()
                ->map(fn ($e) => ['event' => (string) $e->event, 'detail' => $e->detail, 'by' => $e->by, 'at' => CarbonImmutable::parse((string) $e->at)->setTimezone($tz)->format('j M Y, H:i')])->all(),
            'canManage' => Gate::allows('leads.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('leads.manage');
        $d = $request->validate(['contact' => ['required', 'string'], 'title' => ['required', 'string', 'max:160'], 'value' => ['nullable', 'numeric', 'min:0', 'max:100000000']]);
        $contact = Contact::query()->whereNull('merged_into')->whereKey($d['contact'])->firstOrFail();
        $limit = $this->restriction($request);
        $id = $this->leads->open($contact->id, $d['title'], 'manual', isset($d['value']) ? (int) round((float) $d['value'] * 100) : null, $limit[0] ?? null, null);

        return redirect()->to($request->getSchemeAndHttpHost().'/'.$this->context->current()->handle().'/leads/'.$id)->with('status', 'Lead added.');
    }

    public function move(Request $request, string $lead): RedirectResponse
    {
        $l = $this->editable($request, $lead);
        $this->leads->move($l, (string) $request->validate(['stage' => ['required', 'string']])['stage'], $this->who($request));

        return back()->with('status', 'Lead moved.');
    }

    public function assign(Request $request, string $lead): RedirectResponse
    {
        $l = $this->editable($request, $lead);
        $member = $request->validate(['member' => ['nullable', 'string', Rule::in(array_keys($this->teamNames()))]])['member'] ?? null;
        $this->leads->assign($l, $member, $member === null ? null : ($this->teamNames()[$member] ?? null), $this->who($request));

        return back()->with('status', $member === null ? 'Lead unassigned.' : 'Lead assigned.');
    }

    public function followUp(Request $request, string $lead): RedirectResponse
    {
        $l = $this->editable($request, $lead);
        $at = $request->validate(['at' => ['nullable', 'date']])['at'] ?? null;
        $this->leads->followUp($l, $at === null ? null : CarbonImmutable::parse((string) $at, $this->context->current()->timezone())->setTimezone((string) config('app.timezone'))->toDateTimeString() /* stored in the app's time zone, as the model reads it */, $this->who($request));

        return back()->with('status', $at === null ? 'Follow-up cleared.' : 'Follow-up set.');
    }

    public function note(Request $request, string $lead): RedirectResponse
    {
        $l = $this->editable($request, $lead);
        $this->leads->note($l, trim((string) $request->validate(['text' => ['required', 'string', 'max:1000']])['text']), $this->who($request));

        return back()->with('status', 'Note added.');
    }

    /** Rename, add and reorder the pipeline's stages (owners: stages are business-wide). */
    public function stagesUpdate(Request $request): RedirectResponse
    {
        Gate::authorize('leads.manage');
        if ($this->restriction($request) !== null) { // stages are business-wide
            return back()->withErrors(['stages' => 'Only someone who looks after every branch can change the stages.']);
        }
        $pipeline = $this->leads->pipeline();
        $d = $request->validate(['stages' => ['required', 'array', 'min:2', 'max:12'], 'stages.*.id' => ['nullable', 'string'], 'stages.*.name' => ['required', 'string', 'max:60'],
            'stages.*.kind' => ['required', 'in:open,won,lost']]);
        $kinds = array_column($d['stages'], 'kind');
        if (! in_array('open', $kinds, true)) {
            return back()->withErrors(['stages' => 'Keep at least one open stage.']);
        }
        // Stages left out are removed — only when no leads are in them.
        $kept = array_values(array_filter(array_map(fn (array $s) => $s['id'] ?? null, $d['stages'])));
        foreach (Stage::query()->where('pipeline_id', $pipeline->id)->whereNotIn('id', $kept)->get() as $gone) {
            if (Lead::query()->withoutGlobalScopes()->where('stage_id', $gone->id)->exists()) {
                return back()->withErrors(['stages' => "“{$gone->name}” still has leads — move them first."]);
            }
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($d, $pipeline, $kept): void {
            Stage::query()->where('pipeline_id', $pipeline->id)->whereNotIn('id', $kept)->delete();
            foreach (array_values($d['stages']) as $i => $s) {
                $stage = isset($s['id']) ? Stage::query()->where('pipeline_id', $pipeline->id)->whereKey($s['id'])->firstOrFail() : new Stage(['pipeline_id' => $pipeline->id]);
                $stage->fill(['name' => trim($s['name']), 'kind' => $s['kind'], 'position' => $i])->save();
            }
        });

        return back()->with('status', 'Stages saved.');
    }

    /** @return list<array{id: string, name: string, kind: string}> */
    private function stages(string $pipelineId): array
    {
        return array_values(Stage::query()->where('pipeline_id', $pipelineId)->orderBy('position')->get()->map(fn (Stage $s) => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind])->all());
    }

    /** @return array<string, string> member id => name (active team) */
    private function teamNames(): array
    {
        return array_column(array_filter($this->team->roster(), fn (array $m) => $m['active']), 'name', 'id');
    }

    /** @return list<string>|null */
    private function restriction(Request $request): ?array
    {
        return app(BranchRestriction::class)->branchRestriction((string) $request->user()?->getAuthIdentifier());
    }

    /** @return Builder<Lead> leads this person may see: branch managers only their branches' */
    private function visible(Request $request): Builder
    {
        $limit = $this->restriction($request);

        return Lead::query()->when($limit !== null, fn (Builder $b) => $b->whereIn('branch_id', $limit));
    }

    private function editable(Request $request, string $lead): Lead
    {
        Gate::authorize('leads.manage');

        return $this->visible($request)->whereKey($lead)->firstOrFail();
    }

    private function who(Request $request): string
    {
        return trim((string) data_get($request->user(), 'first_name').' '.(string) data_get($request->user(), 'last_name')) ?: 'Someone';
    }
}
