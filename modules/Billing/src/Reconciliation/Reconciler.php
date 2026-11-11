<?php

declare(strict_types=1);

namespace Wurk\Billing\Reconciliation;

use Carbon\CarbonImmutable;
use Wurk\Billing\Metrics\Costs;
use Wurk\Messaging\Contracts\DeliveryLog;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * A provider's statement against WURK's own record of what it sent (Sprint 15.5c): by the provider's message ID, or —
 * when the statement has none — by number (hashed exactly as WURK keeps it) and day, counting repeats. Nothing from the
 * statement is kept; the result names no numbers.
 */
final readonly class Reconciler
{
    public function __construct(private DeliveryLog $log, private Costs $costs, private TenantDirectory $tenants) {}

    /**
     * @param  list<list<string>>  $rows  the statement's data rows
     * @param  array{id: int|null, number: int|null, date: int|null, cost: int|null}  $cols  column positions
     * @return array<string, mixed>
     */
    public function run(array $rows, array $cols, string $driver, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ours = $this->log->sent($driver, $from, $to);
        $byId = $cols['id'] !== null;
        $key = fn (array $r): ?string => $byId ? (trim((string) ($r[$cols['id']] ?? '')) ?: null)
            : (($h = $this->log->hashFor((string) ($r[$cols['number'] ?? -1] ?? ''))) === null || ($d = self::day($r[$cols['date'] ?? -1] ?? null)) === null ? null : $h.'|'.$d);
        $theirs = [];
        $unreadable = 0;
        $statementCents = 0;
        foreach ($rows as $i => $r) {
            if ($cols['cost'] !== null) {
                $statementCents += self::milliCents((string) ($r[$cols['cost']] ?? ''));
            }
            ($k = $key($r)) === null ? $unreadable++ : $theirs[$k][] = $i;
        }
        $mine = [];
        $wurkOnly = [];
        foreach ($ours as $o) {
            $k = $byId ? $o['provider_message_id'] : $o['to_hash'].'|'.$o['day'];
            if ($k === null || $k === '') {
                $wurkOnly[] = $o; // sent, but the provider gave no ID — can't be on the statement by ID

                continue;
            }
            $mine[$k][] = $o;
        }
        $matched = 0;
        $statementOnly = [];
        foreach ($theirs as $k => $lines) {
            $have = count($mine[$k] ?? []);
            $matched += min($have, count($lines));
            foreach (array_slice($lines, $have) as $i) {
                $statementOnly[] = $rows[$i];
            }
        }
        foreach ($mine as $k => $list) {
            foreach (array_slice($list, count($theirs[$k] ?? [])) as $o) {
                $wurkOnly[] = $o;
            }
        }
        $channel = $ours[0]['channel'] ?? 'sms';
        $per1000 = $this->costs->providerPer1000($channel, '');
        $expected = $per1000 === null ? null : intdiv(count($ours) * $per1000, 1000);
        $names = [];
        $wurkRows = array_map(function (array $o) use (&$names): array {
            $t = (string) $o['tenant_id'];
            $names[$t] ??= $t === '' ? 'WURK' : ($this->tenants->find($t)?->displayName() ?? 'A business');

            return [$o['day'], $names[$t], (string) ($o['provider_message_id'] ?? '(no provider ID)')];
        }, $wurkOnly);

        return [
            'matchedBy' => $byId ? 'message ID' : 'number and day',
            'statement' => count($rows), 'wurk' => count($ours), 'matched' => $matched, 'statementOnly' => count($statementOnly), 'wurkOnly' => count($wurkOnly), 'unreadable' => $unreadable,
            'statementCents' => $cols['cost'] === null ? null : intdiv($statementCents, 1000), 'expectedCents' => $expected,
            'differenceCents' => $cols['cost'] === null || $expected === null ? null : intdiv($statementCents, 1000) - $expected,
            'statementOnlyRows' => array_map(fn ($r) => [$cols['date'] !== null ? (self::day($r[$cols['date']] ?? null) ?? '') : '', $byId ? (string) ($r[$cols['id']] ?? '') : '(by number)',
                $cols['cost'] !== null ? (string) ($r[$cols['cost']] ?? '') : ''], array_slice($statementOnly, 0, 500)),
            'wurkOnlyRows' => array_slice($wurkRows, 0, 500),
        ];
    }

    /** A cost like "R0.25", "0,25" or "0.2500" in thousandths of a cent (exact for per-message prices). */
    public static function milliCents(string $v): int
    {
        $v = str_replace([' ', 'R', 'r', "\u{00A0}"], '', trim($v));
        if (substr_count($v, ',') === 1 && ! str_contains($v, '.')) {
            $v = str_replace(',', '.', $v); // decimal comma
        }
        $v = str_replace(',', '', $v);

        return is_numeric($v) ? (int) round((float) $v * 100000) : 0;
    }

    /** A statement date as a South African calendar day — day-first formats tried before a general parse (05/03/2027 is 5 March). */
    public static function day(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $datePart = explode(' ', str_replace('T', ' ', $v))[0];
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'Y/m/d', 'd.m.Y', 'j.n.Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $datePart, 'Africa/Johannesburg');
            } catch (\Throwable) {
                continue; // not this format (Carbon throws rather than returning false)
            }
            if ($d instanceof CarbonImmutable && $d->format($format) === $datePart) {
                return $d->toDateString();
            }
        }
        try {
            return CarbonImmutable::parse($v, 'Africa/Johannesburg')->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
