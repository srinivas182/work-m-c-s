<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Platform\Support\SentryDsn;
use Wurk\Watchtower\Jobs\SendToSentry;

/**
 * Sprint 17.5 (decision G): server errors also go to Sentry when it's switched on in Admin → Providers → Error
 * tracking. Only what Watchtower keeps is sent — already masked (no personal data, keys or tokens) — at most once a
 * minute per problem, from the queue, so a page never waits for Sentry.
 */
final readonly class SentryForwarder
{
    public function __construct(private ProviderCredentials $providers) {}

    public function forward(Signal $s): void
    {
        $p = $this->providers->active('errors.sentry');
        if ($p === null || SentryDsn::parse((string) ($p['credentials']['dsn'] ?? '')) === null) {
            return;
        }
        $rate = is_numeric($p['settings']['sample_rate'] ?? null) ? max(0.0, min(1.0, (float) $p['settings']['sample_rate'])) : 1.0;
        if ($rate < 1.0 && mt_rand() / mt_getrandmax() > $rate) {
            return;
        }
        if (! Cache::add('watchtower:sentry:'.$s->fingerprint(), 1, 60)) {
            return; // the same problem again within the minute: Watchtower counts it, Sentry needn't
        }
        SendToSentry::dispatch(self::event($s, (string) ($p['settings']['environment'] ?? '') ?: (string) config('app.env')))->onQueue('bulk');
    }

    /** @return array<string, mixed> */
    public static function event(Signal $s, string $environment): array
    {
        $frames = [];
        foreach (array_reverse(array_slice(explode("\n", (string) $s->trace), 0, 25)) as $line) {
            if (preg_match('/^#?\d*\s*(.+?)\((\d+)\)(?::\s*(.+))?$/', trim($line), $m) === 1) {
                $frames[] = ['filename' => $m[1], 'lineno' => (int) $m[2], 'function' => $m[3] ?? null, 'in_app' => ! str_contains($m[1], 'vendor/')];
            }
        }

        return [
            'event_id' => str_replace('-', '', (string) Str::uuid()),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'platform' => 'php',
            'level' => in_array($s->severity, ['critical', 'high'], true) ? 'error' : 'warning',
            'environment' => mb_substr($environment, 0, 64),
            'release' => 'wurk@'.Release::current(),
            'fingerprint' => [$s->fingerprint()],
            'tags' => array_filter(['kind' => $s->kind, 'surface' => $s->surface, 'location' => $s->location !== null ? mb_substr($s->location, 0, 200) : null]),
            'exception' => ['values' => [array_filter([
                'type' => $s->exceptionClass ?? $s->kind,
                'value' => mb_substr((string) ($s->message ?? $s->title), 0, 1000),
                'stacktrace' => $frames === [] ? null : ['frames' => $frames],
            ])]],
        ];
    }
}
