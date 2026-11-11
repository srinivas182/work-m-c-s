<?php

declare(strict_types=1);

namespace Wurk\Crm\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reports → Leads (Sprint 15.1b): new leads and where they came from, conversion, and how fast the team responds. */
final readonly class LeadsSection
{
    private const SOURCES = ['manual' => 'Added by the team', 'form' => 'Website forms', 'chat' => 'Chat', 'quote' => 'Quote requests'];

    public function __construct(private TenantContext $context) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        [$now, $before] = [$this->stats($p, $p->from, $p->to), $this->stats($p, $p->previousFrom(), $p->previousTo())];
        $highlights = [];
        if ($now['response'] !== null) {
            $highlights[] = 'You answered new leads in '.$this->hours($now['response']).' on average (median).';
        }

        return [
            'title' => 'Leads',
            'metrics' => [
                ['label' => 'New leads', 'value' => $now['new'], 'previous' => $before['new'], 'format' => 'count'],
                ['label' => 'Won (of those closed)', 'value' => $now['conversion'], 'previous' => $before['conversion'], 'format' => 'percent'],
                ['label' => 'First response (hours, median)', 'value' => $now['response'] ?? 0, 'previous' => $before['response'] ?? 0, 'format' => 'count'],
            ],
            'chart' => null,
            'rows' => ['columns' => ['Where new leads came from', 'Leads'], 'data' => array_map(fn ($s, $n) => [self::SOURCES[$s] ?? ucfirst($s), (string) $n], array_keys($now['sources']), array_values($now['sources']))],
            'extra' => null,
            'highlights' => $highlights,
        ];
    }

    private function hours(float $h): string
    {
        return $h < 1 ? 'under an hour' : round($h).' '.((int) round($h) === 1 ? 'hour' : 'hours');
    }

    /** @return array{new: int, conversion: float, response: float|null, sources: array<string, int>} */
    private function stats(ReportPeriod $p, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $branch = fn ($q) => $q->when($p->branchIds !== null, fn ($q) => $q->whereIn('leads.branch_id', $p->branchIds));
        $new = $db->table('leads')->where('tenant_id', $t)->whereBetween('created_at', [$from, $to])->tap($branch)->get(['id', 'source', 'created_at']);
        $closed = $db->table('leads')->join('pipeline_stages', 'pipeline_stages.id', '=', 'leads.stage_id')->where('leads.tenant_id', $t)->where('pipeline_stages.tenant_id', $t)
            ->whereIn('pipeline_stages.kind', ['won', 'lost'])->whereBetween('leads.closed_at', [$from, $to])->tap($branch)->pluck('pipeline_stages.kind');
        $firsts = $new->isEmpty() ? collect() : $db->table('lead_events')->where('tenant_id', $t)->whereIn('lead_id', $new->pluck('id'))->where('event', '!=', 'created')
            ->selectRaw('lead_id, min(at) as first_at')->groupBy('lead_id')->pluck('first_at', 'lead_id');
        $waits = $new->filter(fn ($l) => isset($firsts[$l->id]))->map(fn ($l) => CarbonImmutable::parse((string) $l->created_at)->diffInMinutes(CarbonImmutable::parse((string) $firsts[$l->id])) / 60)->sort()->values();
        $sources = $new->countBy('source')->sortDesc()->all();

        return ['new' => $new->count(), 'conversion' => $closed->isEmpty() ? 0.0 : round($closed->filter(fn ($k) => $k === 'won')->count() / $closed->count() * 100, 1),
            'response' => $waits->isEmpty() ? null : round((float) $waits->median(), 1), 'sources' => array_map('intval', $sources)];
    }
}
