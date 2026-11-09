<?php

declare(strict_types=1);

namespace Wurk\Credits\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Credits\Contracts\UsageHistory;

final class RolledUpUsage implements UsageHistory
{
    public function between(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $out = [];
        foreach (DB::table('credit_usage_daily')->whereBetween('day', [CarbonImmutable::instance($from)->toDateString(), CarbonImmutable::instance($to)->toDateString()])
            ->selectRaw('tenant_id, channel, sum(used_milli) as used, sum(messages) as messages')->groupBy('tenant_id', 'channel')->get() as $r) {
            $out[(string) $r->tenant_id][(string) $r->channel] = ['credits_milli' => (int) $r->used, 'messages' => (int) $r->messages];
        }

        return $out;
    }
}
