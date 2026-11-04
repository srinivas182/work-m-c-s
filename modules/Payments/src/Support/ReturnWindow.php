<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Payments\Contracts\RefundWindow;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class ReturnWindow implements RefundWindow
{
    public const DEFAULT_DAYS = 14;

    public function __construct(private TenantContext $context) {}

    public function days(): int
    {
        $days = DB::connection($this->context->connectionName())->table('payment_preferences')->where('tenant_id', $this->context->id())->value('refund_days');

        return $days === null ? self::DEFAULT_DAYS : (int) $days;
    }

    public function finalAt(\DateTimeInterface $from): CarbonImmutable
    {
        return CarbonImmutable::instance($from)->addDays($this->days());
    }

    public function open(\DateTimeInterface $from): bool
    {
        return $this->days() > 0 && now()->lt($this->finalAt($from));
    }

    public function set(int $days): void
    {
        DB::connection($this->context->connectionName())->table('payment_preferences')->updateOrInsert(['tenant_id' => $this->context->id()], ['refund_days' => $days]);
    }
}
