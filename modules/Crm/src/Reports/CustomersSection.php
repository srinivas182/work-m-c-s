<?php

declare(strict_types=1);

namespace Wurk\Crm\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Crm\Contracts\Takings;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Reports → Customers (Sprint 15.1a): from CRM's own records — new vs returning, repeat rate, lifetime value — and the
 * customers who haven't been back in 60 days (for a win-back campaign, 15.3).
 */
final readonly class CustomersSection implements Takings
{
    public function __construct(private TenantContext $context) {}

    public function recordPurchase(string $type, string $id, int $paidCents, ?string $branchId = null): void
    {
        DB::connection($this->context->connectionName())->table('contact_purchases')->updateOrInsert(
            ['source_type' => $type, 'source_id' => $id], ['tenant_id' => $this->context->id(), 'paid_cents' => max(0, $paidCents), 'branch_id' => $branchId, 'created_at' => now()]);
    }

    public function recordRefund(string $type, string $id, int $refundedCents): void
    {
        DB::connection($this->context->connectionName())->table('contact_purchases')->where('tenant_id', $this->context->id())
            ->where('source_type', $type)->where('source_id', $id)->update(['refunded_cents' => max(0, $refundedCents)]);
    }

    public function net(\DateTimeInterface $from, \DateTimeInterface $to, ?array $branchIds): int
    {
        return (int) DB::connection($this->context->connectionName())->table('contact_purchases')->where('tenant_id', $this->context->id())
            ->whereBetween('created_at', [$from, $to])->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->selectRaw('coalesce(sum(paid_cents - refunded_cents), 0) as net')->value('net');
    }

    public function paidFor(array $sources): int
    {
        if ($sources === []) {
            return 0;
        }
        $q = DB::connection($this->context->connectionName())->table('contact_purchases')->where('tenant_id', $this->context->id())
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$type, $id]) {
                    $q->orWhere(fn ($w) => $w->where('source_type', $type)->where('source_id', $id));
                }
            });

        return (int) $q->selectRaw('coalesce(sum(paid_cents - refunded_cents), 0) as net')->value('net');
    }

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        [$now, $before] = [$this->stats($p, $p->from, $p->to), $this->stats($p, $p->previousFrom(), $p->previousTo())];
        $highlights = [];
        if ($now['seen'] > 0) {
            $highlights[] = "{$now['new']} new ".($now['new'] === 1 ? 'customer' : 'customers').", and {$now['returning']} came back.";
        }
        if ($now['lapsed'] > 0) {
            $highlights[] = "{$now['lapsed']} ".($now['lapsed'] === 1 ? 'customer hasn’t' : 'customers haven’t').' been back in 60 days — a win-back message could bring them in.';
        }

        return [
            'title' => 'Customers',
            'metrics' => [
                ['label' => 'Customers seen', 'value' => $now['seen'], 'previous' => $before['seen'], 'format' => 'count'],
                ['label' => 'New customers', 'value' => $now['new'], 'previous' => $before['new'], 'format' => 'count'],
                ['label' => 'Repeat rate', 'value' => $now['repeat'], 'previous' => $before['repeat'], 'format' => 'percent'],
                ['label' => 'Average lifetime value', 'value' => $now['ltv'], 'previous' => $before['ltv'], 'format' => 'money'],
            ],
            'chart' => null, 'rows' => null, 'extra' => null,
            'highlights' => $highlights,
        ];
    }

    /** @return array{seen: int, new: int, returning: int, repeat: float, ltv: int, lapsed: int} */
    private function stats(ReportPeriod $p, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $links = $db->table('contact_links')->where('tenant_id', $t)->where('occurred_at', '<=', $to)
            ->when($p->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $p->branchIds))
            ->get(['contact_id', 'source_type', 'source_id', 'occurred_at'])->groupBy('contact_id');
        $seen = $links->filter(fn ($l) => $l->contains(fn ($x) => CarbonImmutable::parse((string) $x->occurred_at)->between($from, $to)));
        $new = $seen->filter(fn ($l) => CarbonImmutable::parse((string) $l->min('occurred_at'))->gte($from));
        $paid = $db->table('contact_purchases')->where('tenant_id', $t)->get(['source_type', 'source_id', 'paid_cents', 'refunded_cents'])
            ->keyBy(fn ($r) => $r->source_type.':'.$r->source_id);
        $value = $seen->map(fn ($l) => (int) $l->sum(fn ($x) => (($r = $paid[$x->source_type.':'.$x->source_id] ?? null) === null) ? 0 : (int) $r->paid_cents - (int) $r->refunded_cents));
        $buyers = $value->filter(fn ($v) => $v > 0);
        $lapsed = $links->filter(fn ($l) => CarbonImmutable::parse((string) $l->max('occurred_at'))->lt($to->subDays(60)))->count();

        return ['seen' => $seen->count(), 'new' => $new->count(), 'returning' => $seen->count() - $new->count(),
            'repeat' => $seen->isEmpty() ? 0.0 : round($seen->filter(fn ($l) => $l->count() > 1)->count() / $seen->count() * 100, 1),
            'ltv' => $buyers->isEmpty() ? 0 : intdiv((int) $buyers->sum(), $buyers->count()), 'lapsed' => $lapsed];
    }
}
