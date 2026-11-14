<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Watchtower\Models\Incident;
use Wurk\Watchtower\Support\Alerts;
use Wurk\Watchtower\Support\FixRequest;
use Wurk\Watchtower\Support\Heartbeats;
use Wurk\Watchtower\Support\Release;
use Wurk\Watchtower\Support\SystemChecks;

/** Admin → Watchtower (Sprint 18.0): every problem on the platform, the state of each system, and who gets emailed. */
final class IncidentsController
{
    public const VIEWS = ['open' => 'Open', 'fixed' => 'Fixed', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'];

    public function index(Request $request, Alerts $alerts): Response
    {
        $f = $this->filters($request);
        $rows = Incident::query()
            ->when($f['view'] === 'open', fn ($q) => $q->whereIn('status', Incident::OPEN), fn ($q) => $f['view'] === 'all' ? $q : $q->where('status', $f['view']))
            ->when($f['severity'] !== '', fn ($q) => $q->where('severity', $f['severity']))
            ->when($f['kind'] !== '', fn ($q) => $q->where('kind', $f['kind']))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('last_seen_at')
            ->paginate(50)->withQueryString();
        $open = Incident::query()->whereIn('status', Incident::OPEN)->toBase()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->all();
        $pulse = Heartbeats::pulse();

        return Inertia::render('Watchtower::Incidents', [
            'filters' => $f, 'views' => self::VIEWS, 'severities' => Incident::SEVERITIES, 'kinds' => Incident::KINDS, 'release' => Release::current(),
            'summary' => ['critical' => (int) ($open['critical'] ?? 0), 'high' => (int) ($open['high'] ?? 0), 'normal' => (int) ($open['normal'] ?? 0),
                'today' => Incident::query()->where('first_seen_at', '>=', now()->startOfDay())->count()],
            'checks' => SystemChecks::latest(),
            'tasks' => DB::table('watchtower_heartbeats')->orderBy('task')->get()->map(fn ($t) => ['task' => $t->task, 'expression' => $t->expression,
                'last_started_at' => $t->last_started_at, 'last_status' => $t->last_status, 'last_runtime_ms' => $t->last_runtime_ms])->values()->all(),
            'scheduler' => $pulse,
            'recipients' => $alerts->recipients(),
            'can_manage' => Gate::allows('watchtower.manage'),
            'rows' => ['data' => collect($rows->items())->map(fn (Incident $i) => $this->row($i))->all(), 'total' => $rows->total(), 'next' => $rows->nextPageUrl(), 'prev' => $rows->previousPageUrl()],
        ]);
    }

    public function show(Request $request, Incident $incident, TenantDirectory $tenants): Response
    {
        $ids = array_values(DB::table('watchtower_incident_businesses')->where('incident_id', $incident->id)->limit(20)->pluck('tenant_id')->map(fn ($id) => (string) $id)->all());

        return Inertia::render('Watchtower::Incident', [
            'incident' => [...$this->row($incident), 'message' => $incident->message, 'file' => $incident->file, 'line' => $incident->line, 'trace' => $incident->trace,
                'context' => $incident->context, 'first_release' => $incident->first_release, 'fixed_in' => $incident->fixed_in, 'regressed_at' => $incident->regressed_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(), 'analysis' => $incident->analysis],
            'days' => DB::table('watchtower_incident_days')->where('incident_id', $incident->id)->where('day', '>=', now()->subDays(13)->toDateString())->orderBy('day')
                ->get(['day', 'count'])->map(fn ($d) => ['day' => (string) $d->day, 'count' => (int) $d->count])->all(),
            'businesses' => array_map(fn ($t) => ['id' => $t->tenantId(), 'name' => $t->displayName(), 'handle' => $t->handle()], $tenants->activeAmong($ids)),
            'fix_request' => FixRequest::for($incident),
            'release' => Release::current(),
            'statuses' => Incident::STATUSES,
            'can_manage' => Gate::allows('watchtower.manage'),
        ]);
    }

    /** Acknowledge, mark fixed (in a release — if it comes back after that, it's a regression), ignore, or reopen. */
    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $d = $request->validate(['action' => ['required', Rule::in(['acknowledge', 'fixed', 'ignore', 'reopen'])], 'fixed_in' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-]+$/']]);
        $by = (string) $request->user()?->getAuthIdentifier();
        $action = $request->string('action')->toString();
        $values = match ($action) {
            'acknowledge' => ['status' => 'acknowledged'],
            'fixed' => ['status' => 'fixed', 'fixed_in' => $d['fixed_in'] ?? Release::current(), 'fixed_at' => now(), 'regressed_at' => null, 'ongoing' => false],
            'ignore' => ['status' => 'ignored'],
            default => ['status' => 'new', 'resolved_at' => null], // reopen
        };
        $incident->forceFill([...$values, 'updated_by' => $by])->save();

        return back()->with('status', match ($action) {
            'acknowledge' => 'Acknowledged — no more emails unless it gets worse.',
            'fixed' => "Marked fixed in {$incident->fixed_in}. If it happens again you’ll be told it came back.",
            'ignore' => 'Ignored — it will still be counted, but never emailed.',
            default => 'Reopened.',
        });
    }

    public function settings(Request $request, Alerts $alerts): RedirectResponse
    {
        $d = $request->validate(['recipients' => ['present', 'array', 'max:5'], 'recipients.*' => ['required', 'email:rfc', 'max:160']],
            ['recipients.max' => 'Up to 5 email addresses.', 'recipients.*.email' => 'That isn’t an email address.']);
        $emails = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim((string) $e)), $d['recipients'])));
        $alerts->setRecipients($emails, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', $emails === [] ? 'No one will be emailed — problems are still recorded here.' : 'Saved. Problem reports go to '.implode(', ', $emails).'.');
    }

    /** @return array<string, mixed> */
    private function row(Incident $i): array
    {
        return ['id' => $i->id, 'reference' => $i->reference(), 'kind' => $i->kind, 'severity' => $i->severity, 'status' => $i->status, 'title' => $i->title,
            'location' => $i->location, 'surface' => $i->surface, 'occurrences' => $i->occurrences, 'last_hour' => $i->lastHour(), 'businesses' => $i->businesses,
            'last_release' => $i->last_release, 'ongoing' => $i->ongoing, 'regressed' => $i->regressed_at !== null && in_array($i->status, Incident::OPEN, true),
            'first_seen_at' => $i->first_seen_at->toIso8601String(), 'last_seen_at' => $i->last_seen_at->toIso8601String()];
    }

    /** @return array{view: string, severity: string, kind: string} */
    private function filters(Request $request): array
    {
        $view = (string) $request->query('view', 'open');
        $severity = (string) $request->query('severity', '');
        $kind = (string) $request->query('kind', '');

        return ['view' => array_key_exists($view, self::VIEWS) ? $view : 'open', 'severity' => array_key_exists($severity, Incident::SEVERITIES) ? $severity : '',
            'kind' => array_key_exists($kind, Incident::KINDS) ? $kind : ''];
    }
}
