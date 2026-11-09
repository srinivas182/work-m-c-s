<?php

declare(strict_types=1);

namespace Wurk\Credits\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;

/** Admin → Metrics → Credits used (Sprint 15.4b): by channel, from the nightly roll-up (up to yesterday). */
final class UsageSection
{
    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        [$now, $before] = [$this->used($p->from, $p->to), $this->used($p->previousFrom(), $p->previousTo())];
        $rows = [];
        foreach ($now as $channel => $milli) {
            $rows[] = [$channel === 'sms' ? 'SMS' : ucfirst((string) $channel), number_format($milli / 1000, 0, '.', ' ')];
        }

        return [
            'title' => 'Credits used',
            'metrics' => [['label' => 'Credits used', 'value' => intdiv((int) array_sum($now), 1000), 'previous' => intdiv((int) array_sum($before), 1000), 'format' => 'count']],
            'chart' => null, 'rows' => ['columns' => ['Channel', 'Credits'], 'data' => $rows], 'extra' => null, 'highlights' => [],
            'note' => 'Counted up to yesterday (rolled up nightly). Credit sales are in Revenue.',
        ];
    }

    /** @return array<string, int> channel => milli-credits */
    private function used(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_map('intval', DB::table('credit_usage_daily')->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('channel, sum(used_milli) as used')->groupBy('channel')->orderByDesc('used')->pluck('used', 'channel')->all());
    }
}
