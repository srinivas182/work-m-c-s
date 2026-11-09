<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Agents\Models\Agent;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** The agent's own figures (Sprint 14.6a): their businesses, their commission, their next payout. Nobody else's. */
final readonly class AgentDashboard
{
    public function __construct(private AgentCommission $commission, private AgentPayouts $payouts, private Catalog $catalog, private TenantDirectory $tenants) {}

    /** @return list<array{id: string, name: string, joined: string, package: string|null, status: string, monthly_cents: int, earned_cents: int}> at-risk first */
    public function businesses(Agent $agent): array
    {
        $earned = DB::table('agent_ledger')->where('agent_id', $agent->id)->whereIn('kind', ['earned', 'reversed'])->whereNotNull('tenant_id')
            ->selectRaw('tenant_id, sum(cents) as cents')->groupBy('tenant_id')->pluck('cents', 'tenant_id');
        $rows = [];
        foreach (DB::table('agent_businesses')->where('agent_id', $agent->id)->orderBy('attributed_at')->get() as $b) {
            $t = $this->tenants->find((string) $b->tenant_id);
            $sub = $this->catalog->subscriptionOf((string) $b->tenant_id);
            $status = match ($sub['status'] ?? null) { // Plans: trial | pending_payment | active | past_due | suspended
                'trial' => 'trial',
                'pending_payment' => 'new',       // signed up; first payment due
                'active' => 'active',
                'past_due' => 'at_risk',
                default => 'churned',              // suspended, or no subscription
            };
            $rows[] = ['id' => (string) $b->tenant_id, 'name' => $t?->displayName() ?? 'A business', 'joined' => substr((string) $b->attributed_at, 0, 10),
                'package' => $sub['package'] ?? null, 'status' => $status,
                'monthly_cents' => $sub === null ? 0 : ($sub['period'] === 'yearly' ? intdiv($sub['period_cents'], 12) : $sub['period_cents']),
                'earned_cents' => (int) ($earned[$b->tenant_id] ?? 0)];
        }
        usort($rows, fn ($a, $b) => ($b['status'] === 'at_risk') <=> ($a['status'] === 'at_risk')); // at risk first; otherwise as they joined

        return $rows;
    }

    /** @return array<string, mixed> */
    public function summary(Agent $agent): array
    {
        $businesses = $this->businesses($agent);
        $counts = array_count_values(array_column($businesses, 'status'));
        $monthStart = now()->startOfMonth();
        $lines = DB::table('agent_ledger')->where('agent_id', $agent->id)->whereIn('kind', ['earned', 'reversed'])->get(['cents', 'created_at', 'available_at']);

        // Next payout — the same rules finance's preview uses.
        $preview = $this->payouts->preview();
        $next = ['state' => 'nothing', 'reason' => null];
        foreach (['included' => 'included', 'held' => 'held', 'skipped' => 'skipped'] as $group => $state) {
            foreach ($preview[$group] as $row) {
                if ($row['agent_id'] === $agent->id) {
                    $next = ['state' => $state, 'reason' => $row['reason'] ?? null];
                }
            }
        }

        $upcoming = [];
        foreach ($lines->filter(fn ($l) => now()->lt($l->available_at) && now()->addDays(30)->gte($l->available_at)) as $l) {
            $day = substr((string) $l->available_at, 0, 10);
            $upcoming[$day] = ($upcoming[$day] ?? 0) + (int) $l->cents;
        }
        ksort($upcoming);
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[now()->startOfMonth()->subMonths($i)->format('Y-m')] = 0;
        }
        foreach ($lines as $l) {
            $m = substr((string) $l->created_at, 0, 7);
            if (array_key_exists($m, $months)) {
                $months[$m] += (int) $l->cents;
            }
        }

        return [
            'businesses' => ['total' => count($businesses), 'this_month' => count(array_filter($businesses, fn ($b) => $b['joined'] >= $monthStart->toDateString())),
                'active' => ($counts['active'] ?? 0) + ($counts['trial'] ?? 0) + ($counts['new'] ?? 0), 'at_risk' => $counts['at_risk'] ?? 0, 'churned' => $counts['churned'] ?? 0],
            'commission' => $this->commission->balance($agent->id),
            'this_month_cents' => (int) $lines->filter(fn ($l) => $monthStart->lte($l->created_at))->sum('cents'),
            'next_payout' => $next + ['minimum_cents' => $this->payouts->minimum()],
            'upcoming' => array_map(fn ($d, $c) => ['date' => $d, 'cents' => $c], array_keys($upcoming), array_values($upcoming)),
            'months' => array_map(fn ($m, $c) => ['month' => $m, 'cents' => $c], array_keys($months), array_values($months)),
        ];
    }

    /** @return list<array{at: string, business: string, level: int, kind: string, cents: int, payable: string, pending: bool}> newest first */
    public function earnings(Agent $agent, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = DB::table('agent_ledger')->where('agent_id', $agent->id)->whereIn('kind', ['earned', 'reversed'])->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')->get(['tenant_id', 'level', 'kind', 'cents', 'available_at', 'created_at']);
        $names = [];
        foreach ($lines->pluck('tenant_id')->filter()->unique() as $t) {
            $names[(string) $t] = $this->tenants->find((string) $t)?->displayName() ?? 'A business';
        }

        return array_values($lines->map(fn ($l) => ['at' => substr((string) $l->created_at, 0, 10), 'business' => $names[(string) $l->tenant_id] ?? 'A business',
            'level' => (int) $l->level, 'kind' => (string) $l->kind, 'cents' => (int) $l->cents, 'payable' => substr((string) $l->available_at, 0, 10),
            'pending' => now()->lt($l->available_at)])->all());
    }

    /** @return list<array{level: int, name: string, code: string, status: string, businesses: int}> the downline by level — counts only, never their earnings */
    public function team(Agent $agent): array
    {
        $out = [];
        $level = [$agent->id];
        for ($depth = 1; $depth <= AgentCommission::MAX_LEVELS && $level !== []; $depth++) {
            $members = Agent::query()->whereIn('sponsor_id', $level)->orderBy('created_at')->get();
            $names = app(PeopleDirectory::class)->names(array_values(array_map('strval', $members->pluck('identity_id')->all())));
            $counts = DB::table('agent_businesses')->whereIn('agent_id', $members->pluck('id'))->selectRaw('agent_id, count(*) as n')->groupBy('agent_id')->pluck('n', 'agent_id');
            foreach ($members as $m) {
                $out[] = ['level' => $depth, 'name' => $names[$m->identity_id] ?? 'Agent', 'code' => $m->code, 'status' => $m->status, 'businesses' => (int) ($counts[$m->id] ?? 0)];
            }
            $level = array_values(array_map('strval', $members->pluck('id')->all()));
        }

        return $out;
    }

    /** @return list<array{reference: string, date: string, cents: int, bank: string, status: string}> newest first */
    public function payouts(Agent $agent): array
    {
        return array_values(DB::table('agent_payout_items')->join('agent_payout_batches', 'agent_payout_batches.id', '=', 'agent_payout_items.batch_id')
            ->where('agent_payout_items.agent_id', $agent->id)->where('agent_payout_batches.status', '!=', 'cancelled')->orderByDesc('agent_payout_batches.created_at')
            ->get(['agent_payout_batches.reference', 'agent_payout_batches.paid_at', 'agent_payout_batches.created_at', 'agent_payout_items.cents', 'agent_payout_items.bank',
                'agent_payout_items.account_last4', 'agent_payout_items.status'])
            ->map(fn ($r) => ['reference' => (string) $r->reference, 'date' => substr((string) ($r->paid_at ?? $r->created_at), 0, 10), 'cents' => (int) $r->cents,
                'bank' => $r->bank.' ·· '.$r->account_last4, 'status' => $r->status === 'ready' ? 'being_paid' : (string) $r->status])->all());
    }
}
