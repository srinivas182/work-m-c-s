<?php

declare(strict_types=1);

namespace Wurk\Audit\Chain;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Appends events to the right hash chain. Each append locks the chain head,
 * so concurrent writers can never fork a chain or reuse a sequence number.
 */
final readonly class Recorder implements AuditLog
{
    public const PLATFORM = 'platform';

    public function __construct(private TenantContext $context, private Application $app) {}

    public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $changes = [], array $options = []): void
    {
        $platform = ($options['platform'] ?? false) || ! $this->context->has();
        $connection = $platform ? DB::connection() : DB::connection($this->context->connectionName());
        $scope = $platform ? self::PLATFORM : $this->context->id();

        $user = Auth::user();
        $actorId = array_key_exists('actor_id', $options) ? $options['actor_id'] : ($user?->getAuthIdentifier());
        $actorName = $options['actor_name'] ?? ($user !== null && (string) $user->getAuthIdentifier() === (string) $actorId ? self::nameOf($user) : null);
        // An HTTP request is one the router matched; commands and queued jobs have none.
        $current = $this->app->bound('request') ? $this->app->make(Request::class) : null;
        $request = $current?->route() !== null ? $current : null;
        $actorType = $options['actor_type'] ?? ($actorId !== null ? 'person' : ($request === null ? 'system' : 'guest'));
        $redacted = self::redact($changes);

        // Make sure the chain head exists *before* locking it. Creating it inside the
        // locking transaction takes a shared lock that concurrent writers then try
        // to upgrade — a classic MySQL deadlock (found by the concurrency check).
        $connection->table('audit_chain_heads')->insertOrIgnore([
            'scope' => $scope, 'tenant_id' => $platform ? null : $scope, 'seq' => 0, 'hash' => Hasher::GENESIS,
        ]);

        // Retried automatically on transient deadlocks / lock timeouts.
        $connection->transaction(function () use ($connection, $scope, $action, $subjectType, $subjectId, $redacted, $actorId, $actorName, $actorType, $request, $platform): void {
            $head = (array) $connection->table('audit_chain_heads')->where('scope', $scope)->lockForUpdate()->firstOrFail();

            $row = [
                'id' => (string) Str::ulid(),
                'scope' => $scope,
                'seq' => (int) $head['seq'] + 1,
                'tenant_id' => $platform ? null : $scope,
                'branch_id' => null,
                'actor_type' => $actorType,
                'actor_identity_id' => $actorId === null ? null : (string) $actorId,
                'actor_name' => $actorName,
                'impersonator_identity_id' => null,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'changes' => $redacted === [] ? null : Hasher::canonical($redacted),
                'ip' => $request?->ip(),
                'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
                'request_id' => Context::get('request_id'),
                'occurred_at' => now()->format('Y-m-d H:i:s.u'),
                'prev_hash' => (string) $head['hash'],
            ];
            $row['hash'] = Hasher::hash($row);

            $connection->table('audit_events')->insert($row);
            $connection->table('audit_chain_heads')->where('scope', $scope)->update(['seq' => $row['seq'], 'hash' => $row['hash']]);
        }, attempts: 5);
    }

    private static function nameOf(Authenticatable $user): ?string
    {
        if (method_exists($user, 'fullName')) {
            return (string) $user->fullName();
        }

        return null;
    }

    /**
     * Replaces values of sensitive keys at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        /** @var list<string> $sensitive */
        $sensitive = config('wurk.audit.redact', []);

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
