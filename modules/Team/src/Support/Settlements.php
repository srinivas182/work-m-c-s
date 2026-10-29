<?php

declare(strict_types=1);

namespace Wurk\Team\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Team\Contracts\BranchLedger;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class Settlements implements BranchLedger
{
    public function __construct(private TenantContext $context, private BranchDirectory $branches) {}

    public function record(?string $receivedBy, ?string $suppliedBy, int $cents, string $kind, string $reference, string $note): void
    {
        $main = $this->branches->main();
        $from = $receivedBy ?? $main; // the business-wide account counts as the main branch's
        $to = $suppliedBy ?? $main;
        if ($from === $to || $cents === 0) {
            return;
        }
        DB::connection($this->context->connectionName())->table('branch_settlements')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'from_branch_id' => $from, 'to_branch_id' => $to,
            'amount_cents' => $cents, 'kind' => $kind, 'reference' => $reference, 'note' => mb_substr($note, 0, 200), 'occurred_at' => now(),
        ]);
    }

    /**
     * A month's statement: per pair of branches, the total owed and the entries.
     *
     * @return list<array{from: string, to: string, total_cents: int, settled: bool, entries: list<array<string, mixed>>}>
     */
    public function statement(string $month): array
    {
        $rows = DB::connection($this->context->connectionName())->table('branch_settlements')
            ->where('occurred_at', '>=', $month.'-01 00:00:00')->where('occurred_at', '<', date('Y-m-01', (int) strtotime($month.'-01 +1 month')).' 00:00:00')
            ->orderBy('occurred_at')->get();
        $out = [];
        foreach ($rows->groupBy(fn ($r) => $r->from_branch_id.'|'.$r->to_branch_id) as $pair => $entries) {
            [$from, $to] = explode('|', (string) $pair);
            $out[] = ['from' => (string) $from, 'to' => (string) $to, 'total_cents' => (int) $entries->sum('amount_cents'), 'settled' => $entries->every(fn ($e) => $e->settled_at !== null),
                'entries' => array_values($entries->map(fn ($e) => ['kind' => (string) $e->kind, 'reference' => (string) $e->reference, 'note' => (string) $e->note, 'amount_cents' => (int) $e->amount_cents, 'at' => (string) $e->occurred_at])->all())];
        }

        return $out;
    }

    /** Mark a pair's month settled (by hand, outside WURK). */
    public function settle(string $month, string $from, string $to, string $by): int
    {
        return DB::connection($this->context->connectionName())->table('branch_settlements')
            ->where('from_branch_id', $from)->where('to_branch_id', $to)->whereNull('settled_at')
            ->where('occurred_at', '>=', $month.'-01 00:00:00')->where('occurred_at', '<', date('Y-m-01', (int) strtotime($month.'-01 +1 month')).' 00:00:00')
            ->update(['settled_at' => now(), 'settled_by' => $by]);
    }
}
