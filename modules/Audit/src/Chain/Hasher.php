<?php

declare(strict_types=1);

namespace Wurk\Audit\Chain;

/**
 * hash = SHA-256(prev_hash || canonical JSON of the event).
 * Canonical JSON: keys sorted recursively and fixed encoding flags, so the
 * same event always hashes the same way on any database.
 */
final class Hasher
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Fields covered by the hash (everything except the hash itself). */
    public const FIELDS = [
        'id', 'scope', 'seq', 'tenant_id', 'branch_id', 'actor_type', 'actor_identity_id', 'actor_name',
        'impersonator_identity_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip', 'user_agent',
        'request_id', 'occurred_at', 'prev_hash',
    ];

    /**
     * @param  array<string, mixed>  $event  raw column values (changes as JSON string or array;
     *                                       occurred_at as "Y-m-d H:i:s.u")
     */
    public static function hash(array $event): string
    {
        $payload = [];
        foreach (self::FIELDS as $field) {
            $value = $event[$field] ?? null;
            if ($field === 'changes' && is_string($value)) {
                $value = json_decode($value, true);
            }
            if ($field === 'seq' && $value !== null) {
                $value = (int) $value;
            }
            if ($field === 'occurred_at' && $value !== null) {
                $value = substr((string) $value, 0, 26); // Y-m-d H:i:s.uuuuuu on every driver
            }
            $payload[$field] = $value;
        }

        return hash('sha256', (string) ($event['prev_hash'] ?? '').self::canonical($payload));
    }

    public static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sort(...), $value);
    }
}
