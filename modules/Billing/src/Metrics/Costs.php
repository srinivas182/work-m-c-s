<?php

declare(strict_types=1);

namespace Wurk\Billing\Metrics;

use Illuminate\Support\Facades\DB;

/** What things cost WURK (Sprint 15.5a), as set in Admin → Profitability. Null = not set (never guessed). */
final class Costs
{
    /** Cents per 1,000 messages on a channel in a country (falling back to every country). */
    public function providerPer1000(string $channel, string $country): ?int
    {
        $c = DB::table('cost_settings')->where('kind', 'provider')->where('channel', $channel)->whereIn('country', [$country, ''])->where('package_id', '')
            ->orderByDesc('country')->value('cents');

        return $c === null ? null : (int) $c;
    }

    /** Cents per business per month for a package (falling back to every package). */
    public function runningPerMonth(?string $packageId): ?int
    {
        $c = DB::table('cost_settings')->where('kind', 'running')->where('channel', '')->where('country', '')->whereIn('package_id', [(string) $packageId, ''])
            ->orderByDesc('package_id')->value('cents');

        return $c === null ? null : (int) $c;
    }

    /** @return list<array{kind: string, channel: string, country: string, package_id: string, cents: int}> */
    public function all(): array
    {
        return array_values(DB::table('cost_settings')->orderBy('kind')->orderBy('channel')->orderBy('country')->get(['kind', 'channel', 'country', 'package_id', 'cents'])
            ->map(fn ($r) => ['kind' => (string) $r->kind, 'channel' => (string) $r->channel, 'country' => (string) $r->country, 'package_id' => (string) $r->package_id, 'cents' => (int) $r->cents])->all());
    }

    public function set(string $kind, string $channel, string $country, string $packageId, ?int $cents, ?string $by): void
    {
        $key = ['kind' => $kind, 'channel' => $channel, 'country' => $country, 'package_id' => $packageId];
        if ($cents === null) {
            DB::table('cost_settings')->where($key)->delete();

            return;
        }
        DB::table('cost_settings')->updateOrInsert($key, ['cents' => $cents, 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
    }
}
