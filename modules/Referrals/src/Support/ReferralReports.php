<?php

declare(strict_types=1);

namespace Wurk\Referrals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Referral reports (Sprint 13.9a). Every figure is this business's alone. */
final readonly class ReferralReports
{
    public function __construct(private TenantContext $context, private Wallets $wallets, private ContactBook $contacts) {}

    /**
     * Referral sales vs commission cost, by month.
     *
     * @return array{sales_cents: int, cashback_cents: int, referral_cents: int, months: list<array{month: string, sales_cents: int, cashback_cents: int, referral_cents: int}>}
     */
    public function cost(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $t = $this->context->id();
        $tz = $this->context->current()->timezone();
        $sales = $this->db()->table('referral_purchases')->join('referral_members', 'referral_members.id', '=', 'referral_purchases.member_id')
            ->where('referral_purchases.tenant_id', $t)->where('referral_members.tenant_id', $t)->whereNotNull('referral_members.referrer_id') // the buyer was referred
            ->whereBetween('referral_purchases.occurred_at', [$from, $to])->get(['referral_purchases.occurred_at', 'referral_purchases.paid_cents', 'referral_purchases.refunded_cents']);
        $lines = $this->db()->table('referral_ledger')->where('tenant_id', $t)->whereNotNull('purchase_id')->whereIn('kind', ['earned', 'reversed'])
            ->whereBetween('created_at', [$from, $to])->get(['created_at', 'level', 'cents']);
        $month = fn ($at) => CarbonImmutable::parse((string) $at)->setTimezone($tz)->format('Y-m');
        $months = [];
        foreach ($sales as $p) {
            $months[$month($p->occurred_at)]['sales_cents'] = ($months[$month($p->occurred_at)]['sales_cents'] ?? 0) + (int) $p->paid_cents - (int) $p->refunded_cents;
        }
        foreach ($lines as $l) {
            $key = (int) $l->level === 0 ? 'cashback_cents' : 'referral_cents';
            $months[$month($l->created_at)][$key] = ($months[$month($l->created_at)][$key] ?? 0) + (int) $l->cents;
        }
        ksort($months);
        $rows = array_map(fn (string $m, array $v) => ['month' => $m, 'sales_cents' => $v['sales_cents'] ?? 0, 'cashback_cents' => $v['cashback_cents'] ?? 0, 'referral_cents' => $v['referral_cents'] ?? 0],
            array_keys($months), array_values($months));

        return ['sales_cents' => array_sum(array_column($rows, 'sales_cents')), 'cashback_cents' => array_sum(array_column($rows, 'cashback_cents')),
            'referral_cents' => array_sum(array_column($rows, 'referral_cents')), 'months' => $rows];
    }

    /** @return list<array{name: string, earned_cents: int, brought: int}> */
    public function topReferrers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $t = $this->context->id();
        $earned = $this->db()->table('referral_ledger')->where('tenant_id', $t)->where('level', '>=', 1)->whereIn('kind', ['earned', 'reversed'])
            ->whereBetween('created_at', [$from, $to])->selectRaw('member_id, sum(cents) as cents')->groupBy('member_id')->orderByDesc('cents')->limit(10)->get();
        $ids = $earned->pluck('member_id')->map(fn ($id) => (string) $id)->all();
        $brought = $this->db()->table('referral_members')->where('tenant_id', $t)->whereIn('referrer_id', $ids)->whereBetween('referred_at', [$from, $to])
            ->selectRaw('referrer_id, count(*) as n')->groupBy('referrer_id')->pluck('n', 'referrer_id');
        $contacts = $this->db()->table('referral_members')->where('tenant_id', $t)->whereIn('id', $ids)->pluck('contact_id', 'id');
        $names = $this->contacts->names(array_values(array_map('strval', $contacts->all())));

        return array_values(array_map(fn ($r) => ['name' => $names[(string) ($contacts[$r->member_id] ?? '')] ?? 'Customer', 'earned_cents' => (int) $r->cents,
            'brought' => (int) ($brought[$r->member_id] ?? 0)], $earned->all()));
    }

    /** @return array{wallets_cents: int, pending_cents: int, payouts_waiting_cents: int, expiring_30_days_cents: int} */
    public function liability(): array
    {
        $t = $this->context->id();
        $now = now();
        $perMember = $this->db()->table('referral_ledger')->where('tenant_id', $t)->where('available_at', '<=', $now)->selectRaw('member_id, sum(cents) as cents')->groupBy('member_id')->pluck('cents');

        return [
            'wallets_cents' => (int) $perMember->sum(fn ($c) => max(0, (int) $c)), // what customers could spend or be paid
            'pending_cents' => (int) $this->db()->table('referral_ledger')->where('tenant_id', $t)->where('available_at', '>', $now)->sum('cents'),
            'payouts_waiting_cents' => (int) $this->db()->table('referral_payouts')->where('tenant_id', $t)->where('status', 'requested')->sum('cents'),
            'expiring_30_days_cents' => $this->wallets->expiringBy($now->copy()->addDays(30)) - $this->wallets->expiringBy($now),
        ];
    }

    /** @return array{paid: int, paid_cents: int, declined: int} */
    public function payouts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->db()->table('referral_payouts')->where('tenant_id', $this->context->id())->whereIn('status', ['paid', 'declined'])->whereBetween('decided_at', [$from, $to])->get(['status', 'cents']);

        return ['paid' => $rows->where('status', 'paid')->count(), 'paid_cents' => (int) $rows->where('status', 'paid')->sum('cents'), 'declined' => $rows->where('status', 'declined')->count()];
    }

    private function db(): Connection
    {
        return DB::connection($this->context->connectionName());
    }
}
