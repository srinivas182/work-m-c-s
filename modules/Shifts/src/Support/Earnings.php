<?php

declare(strict_types=1);

namespace Wurk\Shifts\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\TeamTakings;
use Wurk\Platform\Money\Money;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * "What each person earned" (Sprint 26.4): hours clocked, services done and products sold (from Bookings and Sales),
 * commission at each person's rates, and tips — for a month, exported for the bookkeeper. Not payroll (decision E):
 * no PAYE or UIF.
 */
final readonly class Earnings
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private TeamTakings $takings, private Clock $clock, private AuditLog $audit) {}

    /** @return array<string, array{services_bp: int, products_bp: int}> by member id */
    public function rates(): array
    {
        return $this->db()->table('commission_rates')->where('tenant_id', $this->context->id())->get()
            ->mapWithKeys(fn ($r) => [(string) $r->member_id => ['services_bp' => (int) $r->services_bp, 'products_bp' => (int) $r->products_bp]])->all();
    }

    public function setRate(string $memberId, int $servicesBp, int $productsBp, string $by): void
    {
        if (! in_array($memberId, array_column($this->team->roster(), 'id'), true) || $servicesBp < 0 || $servicesBp > 10000 || $productsBp < 0 || $productsBp > 10000) {
            throw ValidationException::withMessages(['rate' => __('Give a percentage from 0 to 100.')]);
        }
        $this->db()->table('commission_rates')->updateOrInsert(['tenant_id' => $this->context->id(), 'member_id' => $memberId],
            ['services_bp' => $servicesBp, 'products_bp' => $productsBp, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('shifts.commission_rate', 'member', $memberId, ['services_bp' => $servicesBp, 'products_bp' => $productsBp, 'by' => $by]);
    }

    /** A tip for a team member, recorded with the payment it came with. */
    public function tip(string $memberId, int $cents, ?string $sourceType, ?string $sourceId, string $by): void
    {
        if ($cents < 1 || $cents > 10_000_000 || ! in_array($memberId, array_column($this->team->roster(), 'id'), true)) {
            throw ValidationException::withMessages(['tip' => __('Give the tip’s amount and who it’s for.')]);
        }
        $this->db()->table('tips')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $memberId, 'cents' => $cents,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'recorded_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('shifts.tip', 'member', $memberId, ['cents' => $cents, 'source' => $sourceType, 'by' => $by]);
    }

    public function monthStart(?string $month = null): CarbonImmutable
    {
        $tz = $this->context->current()->timezone();

        return $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? CarbonImmutable::parse($month.'-01', $tz)->startOfMonth() : CarbonImmutable::now($tz)->startOfMonth();
    }

    /**
     * @return array{rows: list<array{member_id: string, name: string, hours: float, services_cents: int, products_cents: int, commission_cents: int, tips_cents: int, earned_cents: int, services_bp: int, products_bp: int}>,
     *   totals: array{services_cents: int, products_cents: int, commission_cents: int, tips_cents: int}}
     */
    public function month(CarbonImmutable $start): array
    {
        $until = $start->addMonth();
        $roster = $this->team->roster();
        $byIdentity = array_column($roster, 'id', 'identity_id');
        $sums = [];
        foreach ($this->takings->between($start->utc(), $until->utc()) as $t) {
            $member = $t['member_id'] ?? ($t['identity_id'] !== null ? ($byIdentity[$t['identity_id']] ?? null) : null);
            if ($member !== null) {
                $sums[$member][$t['kind']] = ($sums[$member][$t['kind']] ?? 0) + (int) $t['cents'];
            }
        }
        $minutes = $this->clock->minutes($start, $until);
        $tips = $this->db()->table('tips')->where('tenant_id', $this->context->id())->where('created_at', '>=', $start->utc())->where('created_at', '<', $until->utc())
            ->groupBy('member_id')->selectRaw('member_id, sum(cents) as cents')->pluck('cents', 'member_id');
        $rates = $this->rates();
        $rows = [];
        foreach ($roster as $m) {
            $services = (int) ($sums[$m['id']]['services'] ?? 0);
            $products = (int) ($sums[$m['id']]['products'] ?? 0);
            $rate = $rates[$m['id']] ?? ['services_bp' => 0, 'products_bp' => 0];
            $commission = intdiv($services * $rate['services_bp'] + 5000, 10000) + intdiv($products * $rate['products_bp'] + 5000, 10000);
            $tip = (int) ($tips[$m['id']] ?? 0);
            if ($services + $products + $tip === 0 && ! isset($minutes[$m['id']]) && ! $m['active']) {
                continue; // left, with nothing this month
            }
            $rows[] = ['member_id' => $m['id'], 'name' => $m['name'], 'hours' => round(($minutes[$m['id']] ?? 0) / 60, 2), 'services_cents' => $services, 'products_cents' => $products,
                'commission_cents' => $commission, 'tips_cents' => $tip, 'earned_cents' => $commission + $tip, 'services_bp' => $rate['services_bp'], 'products_bp' => $rate['products_bp']];
        }

        return ['rows' => $rows, 'totals' => ['services_cents' => array_sum(array_column($rows, 'services_cents')), 'products_cents' => array_sum(array_column($rows, 'products_cents')),
            'commission_cents' => array_sum(array_column($rows, 'commission_cents')), 'tips_cents' => array_sum(array_column($rows, 'tips_cents'))]];
    }

    /** The month as a spreadsheet (CSV) for the bookkeeper. */
    public function csv(CarbonImmutable $start): string
    {
        $data = $this->month($start);
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('No temp stream.');
        fputcsv($out, ['Team member', 'Hours worked', 'Services done', 'Commission on services (%)', 'Products sold', 'Commission on products (%)', 'Commission', 'Tips', 'Earned (commission + tips)'], escape: '');
        $plain = fn (int $c) => number_format($c / 100, 2, '.', '');
        foreach ($data['rows'] as $r) {
            fputcsv($out, [self::safe($r['name']), $r['hours'], $plain($r['services_cents']), $r['services_bp'] / 100, $plain($r['products_cents']), $r['products_bp'] / 100,
                $plain($r['commission_cents']), $plain($r['tips_cents']), $plain($r['earned_cents'])], escape: '');
        }
        fputcsv($out, ['Total', '', $plain($data['totals']['services_cents']), '', $plain($data['totals']['products_cents']), '', $plain($data['totals']['commission_cents']),
            $plain($data['totals']['tips_cents']), $plain($data['totals']['commission_cents'] + $data['totals']['tips_cents'])], escape: '');
        fputcsv($out, ['Amounts in '.Money::business()->code.'. Not payroll: no PAYE, UIF or other deductions.'], escape: '');
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** A name can't start a spreadsheet formula. */
    private static function safe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v;
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->context->connectionName());
    }
}
