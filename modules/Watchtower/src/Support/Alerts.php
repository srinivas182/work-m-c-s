<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Wurk\Security\Contracts\PlatformSettings;
use Wurk\Watchtower\Jobs\AnalyseIncident;
use Wurk\Watchtower\Mail\IncidentMail;
use Wurk\Watchtower\Mail\WatchtowerDigestMail;
use Wurk\Watchtower\Models\Incident;

/**
 * Who hears about what, and when (Sprint 18.0). Critical and high problems: an email straight away — one per
 * problem, never one per error. Again only if it comes back after a fix, gets ten times worse, or turns more
 * serious. Everything else: the daily digest. Systems that recover say so.
 */
final readonly class Alerts
{
    /** A bad minute shouldn't become 500 emails: the rest wait for the next minute. */
    public const PER_RUN = 20;

    public function __construct(private PlatformSettings $settings) {}

    /** @return list<string> */
    public function recipients(): array
    {
        $saved = $this->settings->get('watchtower.recipients');

        return array_values(array_filter(is_array($saved) ? $saved : (array) config('wurk.watchtower.recipients', []), 'is_string'));
    }

    /** @param list<string> $emails */
    public function setRecipients(array $emails, ?string $by): void
    {
        $this->settings->set('watchtower.recipients', array_values(array_unique($emails)), $by);
    }

    /** Why this incident should be emailed now, or null. */
    public static function reason(Incident $i): ?string
    {
        if ($i->status === 'ignored' || $i->status === 'fixed' || $i->status === 'resolved') {
            return null;
        }
        if ($i->regressed_at !== null && ($i->alerted_at === null || $i->regressed_at->greaterThan($i->alerted_at))) {
            return 'regression';
        }
        if (Incident::rank($i->severity) < Incident::rank('high')) {
            return null;
        }
        if ($i->alerted_at === null) {
            return 'new';
        }
        if (Incident::rank($i->severity) > Incident::rank($i->alerted_severity)) {
            return 'worse';
        }
        // (Not for something still failing — a check failing every minute isn't "ten times worse".)
        if (! $i->ongoing && $i->occurrences >= max(10 * $i->alerted_occurrences, $i->alerted_occurrences + 10)) {
            return 'spike';
        }

        return null;
    }

    /** Emails every incident that's due. Returns how many were sent. */
    public function sendDue(): int
    {
        $to = $this->recipients();
        if ($to === []) {
            return 0;
        }
        $sent = 0;
        $candidates = Incident::query()->whereIn('status', Incident::OPEN)
            ->where(fn ($q) => $q->whereIn('severity', ['critical', 'high'])->orWhereNotNull('regressed_at'))
            ->where('last_seen_at', '>=', now()->subDays(2))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('last_seen_at')
            ->limit(200)->get();
        foreach ($candidates as $i) {
            $reason = self::reason($i);
            if ($reason === null || self::awaitingAnalysis($i)) {
                continue;
            }
            if ($sent >= self::PER_RUN) {
                break;
            }
            if ($this->send($to, new IncidentMail($i, $reason, FixRequest::for($i), $this->link($i)))) {
                $i->forceFill(['alerted_at' => now(), 'alerted_severity' => $i->severity, 'alerted_occurrences' => $i->occurrences])->save();
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Ask the AI to explain problems worth explaining (18.0b) — in the background, a few per minute, within the daily
     * cap. Returns how many were asked for.
     */
    public function requestAnalyses(): int
    {
        if (! (bool) config('wurk.watchtower.ai', true)) {
            return 0;
        }
        $asked = 0;
        $candidates = Incident::query()->whereIn('status', Incident::OPEN)->whereNotIn('kind', ['check', 'queue', 'downtime'])
            ->where('last_seen_at', '>=', now()->subDay())->whereNull('analysed_on')
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('last_seen_at')->limit(50)->get();
        $regressed = Incident::query()->whereIn('status', Incident::OPEN)->whereNotNull('regressed_at')->whereNotNull('analysed_on')
            ->where('last_seen_at', '>=', now()->subDay())->limit(10)->get();
        foreach ($candidates->concat($regressed) as $i) {
            $state = (array) $i->analysis;
            $pending = ($state['status'] ?? null) === 'pending' && ($state['release'] ?? null) === $i->last_release;
            if ($asked >= 5 || ! Analyst::underCap()) {
                break;
            }
            if ($pending || ! Analyst::worthIt($i)) {
                continue;
            }
            $i->forceFill(['analysis' => ['status' => 'pending', 'release' => $i->last_release, 'at' => now()->toIso8601String()]])->save();
            AnalyseIncident::dispatch($i->id);
            $asked++;
        }

        return $asked;
    }

    /**
     * High problems wait up to 3 minutes for the AI's explanation, so the email says what's wrong and how to fix it.
     * Critical ones never wait.
     */
    public static function awaitingAnalysis(Incident $i): bool
    {
        $state = (array) $i->analysis;
        if ($i->severity === 'critical' || ($state['status'] ?? null) !== 'pending') {
            return false;
        }
        $at = is_string($state['at'] ?? null) ? CarbonImmutable::parse($state['at']) : null;

        return $at !== null && $at->greaterThan(now()->subMinutes(3));
    }

    /**
     * The outage reported by the uptime checker is over: how long it lasted, and what Watchtower saw meanwhile.
     *
     * @param  list<Incident>  $during
     */
    public function downtimeOver(Incident $i, CarbonImmutable $from, CarbonImmutable $to, array $during): void
    {
        $emails = $this->recipients();
        if ($emails !== []) {
            $this->send($emails, new IncidentMail($i, 'recovered', FixRequest::for($i), $this->link($i), $during, max(1, (int) round(($to->getTimestamp() - $from->getTimestamp()) / 60))));
        }
    }

    /** A check or backlog that was emailed about has cleared. */
    public function recovered(Incident $i): void
    {
        $to = $this->recipients();
        if ($to !== [] && $i->alerted_at !== null) {
            $this->send($to, new IncidentMail($i, 'recovered', FixRequest::for($i), $this->link($i)));
        }
    }

    /** The daily digest: every new problem of the day, what's still open, and the state of the system. */
    public function digest(): bool
    {
        $to = $this->recipients();
        if ($to === []) {
            return false;
        }
        $since = now()->subDay();
        $new = array_values(Incident::query()->where('first_seen_at', '>=', $since)->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('occurrences')->limit(30)->get()->all());
        $open = Incident::query()->whereIn('status', Incident::OPEN)->toBase()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->all();
        $failing = array_values(DB::table('watchtower_checks')->where('ok', false)->orderBy('name')->get(['name', 'message', 'failing_since'])->all());
        $resolved = Incident::query()->where('resolved_at', '>=', $since)->count();

        return $this->send($to, new WatchtowerDigestMail($new, array_map('intval', $open), $failing, $resolved, $this->link(null)));
    }

    public function link(?Incident $i): string
    {
        return 'https://'.config('wurk.domains.admin').'/watchtower'.($i !== null ? '/'.$i->id : '');
    }

    /** @param list<string> $to */
    private function send(array $to, Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable) {
            return false; // the mail service itself may be what's down; the check for it will say so
        }
    }
}
