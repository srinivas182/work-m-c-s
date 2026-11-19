<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

/**
 * A Sentry DSN (Sprint 17.5, decision G): https://<public key>@<host>/<project id> → where events go and how they're
 * signed. Shared by Watchtower (sending) and Admin → Providers (Test connection).
 */
final readonly class SentryDsn
{
    private function __construct(public string $key, public string $endpoint) {}

    public static function parse(string $dsn): ?self
    {
        $p = parse_url(trim($dsn));
        $project = trim((string) ($p['path'] ?? ''), '/');
        if (($p['scheme'] ?? '') !== 'https' || ($p['user'] ?? '') === '' || ($p['host'] ?? '') === '' || preg_match('/^\d+$/', $project) !== 1) {
            return null;
        }

        return new self((string) $p['user'], 'https://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').'/api/'.$project.'/envelope/');
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_key='.$this->key.', sentry_client=wurk/1.0', 'Content-Type' => 'application/x-sentry-envelope'];
    }

    /** @param array<string, mixed> $event */
    public static function envelope(array $event): string
    {
        return json_encode(['event_id' => $event['event_id'], 'sent_at' => gmdate('Y-m-d\TH:i:s\Z')], JSON_UNESCAPED_SLASHES)."\n"
            .json_encode(['type' => 'event'])."\n".json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
