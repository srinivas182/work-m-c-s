<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Watchtower\Models\Incident;

/**
 * Groups each signal into its incident (Sprint 18.0): counts it, notes the business and release, reopens a problem
 * that was resolved, marks a regression when one that was fixed comes back, and raises severity when it spreads.
 * Never throws — recording a problem must never cause another.
 */
final class Recorder
{
    /** Businesses linked to one incident, at most (the count is what matters after that). */
    private const MAX_BUSINESSES = 500;

    private static bool $recording = false;

    public function __construct(private readonly TenantContext $tenants) {}

    public function record(Signal $s): ?Incident
    {
        if (self::$recording) {
            return null; // a failure while recording a failure: don't loop
        }
        self::$recording = true;
        try {
            return $this->write($s);
        } catch (Throwable) {
            return null;
        } finally {
            self::$recording = false;
        }
    }

    /** A check or backlog that has cleared: the incident is resolved. Returns it if it was ongoing. */
    public function clear(string $kind, string $group): ?Incident
    {
        try {
            $i = Incident::query()->where('fingerprint', sha1($kind.'|'.$group))->where('ongoing', true)->first();
            $i?->forceFill(['ongoing' => false, 'status' => in_array($i->status, Incident::OPEN, true) ? 'resolved' : $i->status, 'resolved_at' => now()])->save();

            return $i;
        } catch (Throwable) {
            return null;
        }
    }

    private function write(Signal $s): Incident
    {
        $now = now();
        $hour = $now->copy()->startOfHour();
        $release = Release::current();
        $fp = $s->fingerprint();
        DB::table('watchtower_incidents')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()), 'fingerprint' => $fp, 'kind' => $s->kind, 'severity' => $s->severity, 'status' => 'new', 'title' => $s->title,
            'exception_class' => $s->exceptionClass, 'message' => $s->message, 'file' => $s->file, 'line' => $s->line, 'location' => $s->location, 'surface' => $s->surface,
            'trace' => $s->trace, 'context' => json_encode($s->context), 'occurrences' => 0, 'businesses' => 0, 'hour_bucket' => $hour, 'hour_count' => 0,
            'first_release' => $release, 'last_release' => $release, 'ongoing' => false, 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $i = Incident::query()->where('fingerprint', $fp)->firstOrFail();

        // Back after it was dealt with: a fixed problem returning is a regression; a quiet one returning is reopened.
        $reopen = [];
        if ($i->status === 'fixed') {
            $reopen = ['status' => 'new', 'regressed_at' => $now, 'resolved_at' => null];
        } elseif ($i->status === 'resolved') {
            $reopen = ['status' => 'new', 'resolved_at' => null, 'alerted_at' => null, 'alerted_severity' => null, 'alerted_occurrences' => 0]; // a new outbreak: tell HQ again
        }

        DB::table('watchtower_incidents')->where('id', $i->id)->update([
            ...$reopen,
            'occurrences' => DB::raw('occurrences + 1'),
            'last_seen_at' => $now, 'last_release' => $release, 'ongoing' => $s->ongoing,
            // The latest details (a check's message changes; the newest trace is the most useful).
            'title' => $s->title, 'message' => $s->message, 'trace' => $s->trace ?? DB::raw('trace'), 'updated_at' => $now,
        ]);
        // This hour's count (for spikes and severity): carry on counting, or start a new hour.
        $counted = DB::table('watchtower_incidents')->where('id', $i->id)->where('hour_bucket', $hour)->increment('hour_count');
        if ($counted === 0) {
            DB::table('watchtower_incidents')->where('id', $i->id)->update(['hour_bucket' => $hour, 'hour_count' => 1]);
        }
        DB::table('watchtower_incident_days')->insertOrIgnore(['incident_id' => $i->id, 'day' => $now->toDateString(), 'count' => 0]);
        DB::table('watchtower_incident_days')->where('incident_id', $i->id)->where('day', $now->toDateString())->increment('count');

        if ($this->tenants->has() && $i->businesses < self::MAX_BUSINESSES) {
            $linked = DB::table('watchtower_incident_businesses')->insertOrIgnore(['incident_id' => $i->id, 'tenant_id' => $this->tenants->id()]);
            if ($linked > 0) {
                DB::table('watchtower_incidents')->where('id', $i->id)->increment('businesses');
            }
        }

        $i->refresh();
        $severity = Severity::for($i, $s->severity);
        if ($severity !== $i->severity) {
            $i->forceFill(['severity' => $severity])->save();
        }

        return $i;
    }
}
