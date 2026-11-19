<?php

declare(strict_types=1);

namespace Wurk\Shifts\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Clocking in and out (Sprint 26.4). Optionally only on the business's Wi-Fi or within 200 m of a place the owner set
 * (decision C) — off by default, because shared phones and patchy GPS are common. The phone's location is checked,
 * never stored.
 */
final readonly class Clock
{
    public const RADIUS_METRES = 200;

    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    /** @return array{clock_check: string, wifi: list<string>, places: list<array{name: string, lat: float, lng: float}>} */
    public function settings(): array
    {
        $r = $this->db()->table('shift_settings')->where('tenant_id', $this->context->id())->first();

        return ['clock_check' => (string) ($r->clock_check ?? 'none'), 'wifi' => array_values((array) json_decode((string) ($r->wifi ?? '[]'), true)),
            'places' => array_values((array) json_decode((string) ($r->places ?? '[]'), true))];
    }

    /**
     * @param  list<string>  $wifi
     * @param  list<array{name: string, lat: float, lng: float}>  $places
     */
    public function saveSettings(string $check, array $wifi, array $places, string $by): void
    {
        if ($check === 'wifi' && $wifi === []) {
            throw ValidationException::withMessages(['clock_check' => __('Add your Wi-Fi first: connect this phone to it and tap “This is our Wi-Fi”.')]);
        }
        if ($check === 'location' && $places === []) {
            throw ValidationException::withMessages(['clock_check' => __('Add your place first: stand there and tap “We’re here”.')]);
        }
        $this->db()->table('shift_settings')->updateOrInsert(['tenant_id' => $this->context->id()],
            ['clock_check' => $check, 'wifi' => json_encode(array_values(array_unique($wifi))), 'places' => json_encode($places), 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('shifts.clock_settings', 'shifts', $this->context->id(), ['check' => $check, 'by' => $by]);
    }

    /** @return array{id: string, in_at: string}|null */
    public function open(string $memberId): ?array
    {
        $r = $this->db()->table('clock_entries')->where('tenant_id', $this->context->id())->where('member_id', $memberId)->whereNull('out_at')->orderByDesc('in_at')->first();

        return $r === null ? null : ['id' => (string) $r->id, 'in_at' => CarbonImmutable::parse((string) $r->in_at)->toIso8601String()];
    }

    public function in(string $memberId, string $ip, ?float $lat, ?float $lng): void
    {
        if ($this->open($memberId) !== null) {
            throw ValidationException::withMessages(['clock' => __('You’re already clocked in.')]);
        }
        $s = $this->settings();
        if ($s['clock_check'] === 'wifi' && ! in_array($ip, $s['wifi'], true)) {
            throw ValidationException::withMessages(['clock' => __('Connect to the business’s Wi-Fi to clock in.')]);
        }
        if ($s['clock_check'] === 'location') {
            $near = $lat !== null && $lng !== null && collect($s['places'])->contains(fn (array $p) => self::metres($lat, $lng, (float) $p['lat'], (float) $p['lng']) <= self::RADIUS_METRES);
            if (! $near) {
                throw ValidationException::withMessages(['clock' => $lat === null ? __('Allow location on this phone to clock in here.') : __('You need to be at work to clock in.')]);
            }
        }
        $this->db()->table('clock_entries')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $memberId, 'in_at' => now(),
            'check' => $s['clock_check'], 'created_at' => now(), 'updated_at' => now()]);
    }

    public function out(string $memberId): int
    {
        $open = $this->open($memberId) ?? throw ValidationException::withMessages(['clock' => __('You’re not clocked in.')]);
        $this->db()->table('clock_entries')->where('tenant_id', $this->context->id())->where('id', $open['id'])->update(['out_at' => now(), 'updated_at' => now()]);

        return (int) CarbonImmutable::parse($open['in_at'])->diffInMinutes(now(), true);
    }

    /** @return array<string, int> minutes worked per member, for entries started in the period and clocked out */
    public function minutes(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $out = [];
        foreach ($this->db()->table('clock_entries')->where('tenant_id', $this->context->id())->whereNotNull('out_at')->where('in_at', '>=', $from)->where('in_at', '<', $until)
            ->get(['member_id', 'in_at', 'out_at']) as $e) {
            $out[(string) $e->member_id] = ($out[(string) $e->member_id] ?? 0)
                + (int) CarbonImmutable::parse((string) $e->in_at)->diffInMinutes(CarbonImmutable::parse((string) $e->out_at), true);
        }

        return $out;
    }

    /** Distance on the earth between two points (haversine), in metres. */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->context->connectionName());
    }
}
