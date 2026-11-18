<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Tenancy\Contracts\TenantContext;

/** The business's record of money taken in person (Sprint 22.3). */
final readonly class InPersonLedger implements InPersonPayments
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function record(string $sourceType, string $sourceId, string $method, int $cents, ?string $takenBy, string $takenByName, ?string $branchId): void
    {
        if (! isset(self::METHODS[$method]) || $cents < 1) {
            throw new InvalidArgumentException('Unknown way of paying, or no amount.');
        }
        DB::connection($this->context->connectionName())->table('in_person_payments')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'source_type' => $sourceType, 'source_id' => $sourceId,
            'method' => $method, 'amount_cents' => $cents, 'taken_by' => $takenBy, 'taken_by_name' => mb_substr($takenByName, 0, 120),
            'branch_id' => $branchId, 'taken_at' => self::db(now()), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('payments.taken_in_person', $sourceType, $sourceId, ['method' => $method, 'cents' => $cents, 'by' => $takenByName]);
    }

    public function takenFor(string $sourceType, string $sourceId): int
    {
        return (int) DB::connection($this->context->connectionName())->table('in_person_payments')
            ->where('tenant_id', $this->context->id())->where('source_type', $sourceType)->where('source_id', $sourceId)->sum('amount_cents');
    }

    public function takenForMany(string $sourceType, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }
        $rows = DB::connection($this->context->connectionName())->table('in_person_payments')->where('tenant_id', $this->context->id())
            ->where('source_type', $sourceType)->whereIn('source_id', $sourceIds)->groupBy('source_id')->selectRaw('source_id, SUM(amount_cents) as cents')->pluck('cents', 'source_id');

        return array_map(fn ($c) => (int) $c, $rows->all());
    }

    public function totals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = DB::connection($this->context->connectionName())->table('in_person_payments')->where('tenant_id', $this->context->id())
            ->where('taken_at', '>=', self::db($from))->where('taken_at', '<', self::db($to))
            ->groupBy('method')->selectRaw('method, SUM(amount_cents) as cents')->pluck('cents', 'method');

        return array_map(fn ($c) => (int) $c, $rows->all());
    }

    /** Stored as wall-clock time in the app's time zone, like every moment on the platform. */
    private static function db(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
