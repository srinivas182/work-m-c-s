<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Watchtower\Models\Incident;

/**
 * Downtime, as seen from outside (Sprint 18.0b). The external uptime checker (Better Stack, or UptimeRobot) emails HQ
 * itself when WURK goes down — WURK may not be able to. Its webhook tells Watchtower too: the outage is recorded (for
 * uptime %), and when it's over Watchtower emails what it saw while it was down.
 */
final readonly class Uptime
{
    public function __construct(private Recorder $recorder, private Alerts $alerts) {}

    public function down(string $monitor, ?string $cause, CarbonImmutable $at): void
    {
        $monitor = mb_substr($monitor, 0, 160);
        if (! DB::table('watchtower_downtimes')->where('monitor', $monitor)->whereNull('ended_at')->exists()) {
            DB::table('watchtower_downtimes')->insert(['id' => strtolower((string) Str::ulid()), 'monitor' => $monitor, 'cause' => $cause === null ? null : mb_substr(Mask::text($cause, 255), 0, 255),
                'started_at' => $at]);
        }
        $i = $this->recorder->record(new Signal('downtime', $monitor, mb_substr("Down: {$monitor}".($cause ? ' — '.Mask::text($cause, 200) : ''), 0, 250), severity: 'critical',
            message: $cause === null ? null : Mask::text($cause, 255), location: "monitor {$monitor}", surface: 'uptime', ongoing: true));
        // The uptime checker has already emailed: don't send the same news twice.
        $i?->forceFill(['alerted_at' => now(), 'alerted_severity' => $i->severity, 'alerted_occurrences' => $i->occurrences])->save();
    }

    public function up(string $monitor, CarbonImmutable $at): void
    {
        $monitor = mb_substr($monitor, 0, 160);
        $open = DB::table('watchtower_downtimes')->where('monitor', $monitor)->whereNull('ended_at')->orderByDesc('started_at')->first();
        if ($open === null) {
            return;
        }
        DB::table('watchtower_downtimes')->where('id', $open->id)->update(['ended_at' => $at]);
        $i = $this->recorder->clear('downtime', $monitor);
        if ($i !== null) {
            $start = CarbonImmutable::parse((string) $open->started_at, (string) config('app.timezone'));
            $this->alerts->downtimeOver($i, $start, $at, $this->seenDuring($start, $at));
        }
    }

    /**
     * What else went wrong while it was down — usually the reason.
     *
     * @return list<Incident>
     */
    private function seenDuring(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(Incident::query()->where('kind', '!=', 'downtime')->where('last_seen_at', '>=', $from->subMinutes(5))->where('first_seen_at', '<=', $to)
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('occurrences')->limit(5)->get()->all());
    }

    /**
     * Uptime over the last 30 days, per monitor that has reported.
     *
     * @return list<array{monitor: string, uptime_pct: float, down_minutes: int, down_now: bool}>
     */
    public static function summary(int $days = 30): array
    {
        $from = now()->subDays($days);
        $total = $days * 24 * 60;
        $out = [];
        foreach (DB::table('watchtower_downtimes')->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from))->get()->groupBy('monitor') as $monitor => $rows) {
            $minutes = 0;
            foreach ($rows as $r) {
                $s = CarbonImmutable::parse((string) $r->started_at, (string) config('app.timezone'));
                $e = $r->ended_at === null ? CarbonImmutable::now() : CarbonImmutable::parse((string) $r->ended_at, (string) config('app.timezone'));
                $minutes += max(0, (int) round(($e->getTimestamp() - max($s->getTimestamp(), $from->getTimestamp())) / 60));
            }
            $out[] = ['monitor' => (string) $monitor, 'uptime_pct' => round(max(0, 100 - 100 * $minutes / $total), 3), 'down_minutes' => $minutes,
                'down_now' => $rows->contains(fn ($r) => $r->ended_at === null)];
        }

        return $out;
    }
}
