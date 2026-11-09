<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Wurk\Agents\Mail\AgentPayoutBouncedMail;
use Wurk\Agents\Mail\AgentRemittanceMail;
use Wurk\Agents\Models\Agent;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * EFT payout batches (Sprint 14.5a). A preview shows who's included, held (banking details changed in the last 7 days)
 * or skipped and why; creating a batch reserves each amount in the agent's ledger at once (`paid_out`, reference
 * `batch:<id>`); the CSV reads account numbers only to build the file, and refuses if banking details changed since the
 * batch was made; cancelling releases the reservations (`payout_returned`).
 */
final readonly class AgentPayouts
{
    public const HOLD_DAYS = 7;

    public function __construct(private AgentCommission $commission, private AgentBanking $banking, private PeopleDirectory $people, private AuditLog $audit) {}

    public function minimum(): int
    {
        return (int) (DB::table('agent_commission_settings')->where('id', 1)->value('min_payout_cents') ?? 0);
    }

    public function setMinimum(int $cents): void
    {
        // Finance may set this before any percentages exist: then the row starts at 0% (decision A).
        DB::table('agent_commission_settings')->insertOrIgnore(['id' => 1, 'level_bp' => '[0]', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_commission_settings')->where('id', 1)->update(['min_payout_cents' => $cents, 'updated_at' => now()]);
        $this->audit->record('agents.min_payout', 'agent_commission_settings', '1', ['cents' => $cents]);
    }

    /** @return array{included: list<array<string, mixed>>, held: list<array<string, mixed>>, skipped: list<array<string, mixed>>} */
    public function preview(): array
    {
        $agents = Agent::query()->orderBy('created_at')->get();
        $names = $this->people->names(array_values(array_map('strval', $agents->pluck('identity_id')->all())));
        $min = $this->minimum();
        $out = ['included' => [], 'held' => [], 'skipped' => []];
        foreach ($agents as $a) {
            $available = $this->commission->balance($a->id)['available'];
            if ($available === 0) {
                continue; // nothing to pay — not worth listing
            }
            $bank = $this->banking->current($a);
            $row = ['agent_id' => $a->id, 'name' => $names[$a->identity_id] ?? 'Agent', 'code' => $a->code, 'cents' => $available,
                'bank' => $bank === null ? null : $bank['bank'].' ·· '.$bank['last4']];
            $reason = match (true) {
                $a->status !== 'active' => 'Suspended',
                $available < 0 => 'Owes WURK',
                $bank === null => 'No banking details',
                $available < $min => 'Below the minimum payout',
                default => null,
            };
            if ($reason !== null) {
                $out['skipped'][] = [...$row, 'reason' => $reason];
            } elseif (now()->subDays(self::HOLD_DAYS)->lt($bank['changed_at'])) {
                $out['held'][] = [...$row, 'reason' => 'Banking details changed on '.$bank['changed_at'].' — check with the agent before paying'];
            } else {
                $out['included'][] = $row;
            }
        }

        return $out;
    }

    /** @throws \DomainException nothing to pay */
    public function create(?string $by): string
    {
        return DB::transaction(function () use ($by): string {
            DB::table('agent_commission_settings')->where('id', 1)->lockForUpdate()->first(); // one batch at a time
            $included = $this->preview()['included'];
            if ($included === []) {
                throw new \DomainException('Nobody is due a payout right now.');
            }
            $id = (string) Str::ulid();
            $n = DB::table('agent_payout_batches')->whereDate('created_at', today())->count() + 1;
            $reference = 'WURK-AG-'.now()->format('Ymd').'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            DB::table('agent_payout_batches')->insert(['id' => $id, 'reference' => $reference, 'status' => 'ready', 'count' => count($included),
                'total_cents' => array_sum(array_column($included, 'cents')), 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($included as $i) {
                $agent = Agent::query()->where('id', (string) $i['agent_id'])->firstOrFail();
                $b = $this->banking->current($agent) ?? throw new \DomainException('Banking details disappeared while the batch was being made.');
                DB::table('agent_payout_items')->insert(['id' => (string) Str::ulid(), 'batch_id' => $id, 'agent_id' => $agent->id, 'cents' => $i['cents'],
                    'holder' => $b['holder'], 'bank' => $b['bank'], 'branch_code' => $b['branch_code'], 'account_type' => $b['account_type'], 'account_last4' => $b['last4'],
                    'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $agent->id, 'tenant_id' => null, 'payment_id' => null, 'level' => null,
                    'kind' => 'paid_out', 'cents' => -$i['cents'], 'bp' => null, 'available_at' => now(), 'reference' => 'batch:'.$id, 'created_at' => now()]);
            }
            $this->audit->record('agents.batch_created', 'agent_payout_batch', $id, ['reference' => $reference, 'count' => count($included), 'total' => array_sum(array_column($included, 'cents')) / 100]);

            return $id;
        });
    }

    /** @throws \DomainException banking details changed since the batch was made */
    public function csv(string $batchId, ?string $by): string
    {
        $batch = DB::table('agent_payout_batches')->where('id', $batchId)->first() ?? throw new \DomainException('No such batch.');
        $rows = [['Account holder', 'Bank', 'Branch code', 'Account type', 'Account number', 'Amount', 'Reference']];
        foreach (DB::table('agent_payout_items')->where('batch_id', $batchId)->where('status', '!=', 'bounced')->orderBy('created_at')->get() as $item) {
            $account = (string) $this->banking->accountNumber((string) $item->agent_id);
            if (substr($account, -4) !== (string) $item->account_last4) {
                throw new \DomainException("{$item->holder} changed their banking details after this batch was made. Cancel it and make a new one.");
            }
            $rows[] = [(string) $item->holder, (string) $item->bank, (string) $item->branch_code, (string) $item->account_type, $account, number_format(((int) $item->cents) / 100, 2, '.', ''), (string) $batch->reference];
        }
        $this->audit->record('agents.batch_downloaded', 'agent_payout_batch', $batchId, ['reference' => $batch->reference]); // who downloaded account numbers, and when
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Could not build the file.');
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) === 1 ? "'".$v : $v, $r)); // no spreadsheet formulas
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @throws \DomainException not cancellable */
    public function cancel(string $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $batch = DB::table('agent_payout_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ($batch === null || $batch->status !== 'ready') {
                throw new \DomainException('Only a batch that hasn’t been paid can be cancelled.');
            }
            foreach (DB::table('agent_payout_items')->where('batch_id', $batchId)->get() as $item) {
                DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $item->agent_id, 'tenant_id' => null, 'payment_id' => null, 'level' => null,
                    'kind' => 'payout_returned', 'cents' => (int) $item->cents, 'bp' => null, 'available_at' => now(), 'reference' => 'batch:'.$batchId.':cancelled', 'created_at' => now()]);
            }
            DB::table('agent_payout_batches')->where('id', $batchId)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->audit->record('agents.batch_cancelled', 'agent_payout_batch', $batchId, ['reference' => $batch->reference]);
        });
    }

    /** Paid through the bank (Sprint 14.5b): locked; each agent gets a remittance advice. @throws \DomainException */
    public function markPaid(string $batchId): void
    {
        /** @var object{reference: string, total_cents: int, created_at: string} $batch */
        $batch = DB::transaction(function () use ($batchId): object {
            $batch = DB::table('agent_payout_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ($batch === null || $batch->status !== 'ready') {
                throw new \DomainException('Only a batch waiting to be paid can be marked paid.');
            }
            DB::table('agent_payout_batches')->where('id', $batchId)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            DB::table('agent_payout_items')->where('batch_id', $batchId)->update(['status' => 'paid', 'updated_at' => now()]);
            $this->audit->record('agents.batch_paid', 'agent_payout_batch', $batchId, ['reference' => $batch->reference, 'total' => $batch->total_cents / 100]);

            return $batch;
        });
        foreach (DB::table('agent_payout_items')->where('batch_id', $batchId)->get() as $item) {
            $agent = Agent::query()->where('id', (string) $item->agent_id)->first();
            $person = $agent === null ? null : ($this->people->contacts([$agent->identity_id])[$agent->identity_id] ?? null);
            if ($agent !== null && ($person['email'] ?? null) !== null) {
                Mail::to((string) $person['email'])->send(new AgentRemittanceMail((string) $person['name'], (int) $item->cents, (string) $batch->reference,
                    $item->bank.' ·· '.$item->account_last4, $this->covers($agent->id, (string) $batch->created_at)));
            }
        }
    }

    /** The bank returned one payment (Sprint 14.5b): the money goes back to the agent's balance; they're asked to check their banking details. @throws \DomainException */
    public function markBounced(string $batchId, string $itemId): void
    {
        /** @var array{agent_id: string, cents: int, reference: string} $item */
        $item = DB::transaction(function () use ($batchId, $itemId): array {
            $batch = DB::table('agent_payout_batches')->where('id', $batchId)->first();
            $item = DB::table('agent_payout_items')->where('batch_id', $batchId)->where('id', $itemId)->lockForUpdate()->first();
            if ($batch === null || $batch->status !== 'paid' || $item === null || $item->status !== 'paid') {
                throw new \DomainException('Only a payment in a paid batch can be marked bounced, once.');
            }
            DB::table('agent_payout_items')->where('id', $itemId)->update(['status' => 'bounced', 'updated_at' => now()]);
            DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $item->agent_id, 'tenant_id' => null, 'payment_id' => null, 'level' => null,
                'kind' => 'payout_returned', 'cents' => (int) $item->cents, 'bp' => null, 'available_at' => now(), 'reference' => 'batch:'.$batchId.':bounced:'.$itemId, 'created_at' => now()]);
            $this->audit->record('agents.payout_bounced', 'agent_payout_batch', $batchId, ['agent' => $item->agent_id, 'cents' => (int) $item->cents]);

            return ['agent_id' => (string) $item->agent_id, 'cents' => (int) $item->cents, 'reference' => (string) $batch->reference];
        });
        $agent = Agent::query()->where('id', $item['agent_id'])->first();
        $person = $agent === null ? null : ($this->people->contacts([$agent->identity_id])[$agent->identity_id] ?? null);
        if (($person['email'] ?? null) !== null) {
            Mail::to((string) $person['email'])->send(new AgentPayoutBouncedMail((string) $person['name'], $item['cents'], $item['reference'], route('agent.bank')));
        }
    }

    /** @return list<array{business: string, cents: int}> commission by business since the agent's previous paid batch, up to this one */
    private function covers(string $agentId, string $upTo): array
    {
        $from = DB::table('agent_payout_items')->join('agent_payout_batches', 'agent_payout_batches.id', '=', 'agent_payout_items.batch_id')
            ->where('agent_payout_items.agent_id', $agentId)->where('agent_payout_batches.status', 'paid')->where('agent_payout_batches.created_at', '<', $upTo)
            ->max('agent_payout_batches.created_at');
        $rows = DB::table('agent_ledger')->where('agent_id', $agentId)->whereIn('kind', ['earned', 'reversed'])->whereNotNull('tenant_id')
            ->where('created_at', '<=', $upTo)->when($from !== null, fn ($q) => $q->where('created_at', '>', $from))
            ->selectRaw('tenant_id, sum(cents) as cents')->groupBy('tenant_id')->get();
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r->cents > 0) {
                $out[] = ['business' => app(TenantDirectory::class)->find((string) $r->tenant_id)?->displayName() ?? 'A business', 'cents' => (int) $r->cents];
            }
        }

        return $out;
    }
}
