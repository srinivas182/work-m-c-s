<?php

declare(strict_types=1);

namespace Wurk\Reports\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Crm\Contracts\Takings;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Reports → Takings and VAT (Sprint 15.1b): everything taken (shop, bookings, in-store sales) net of refunds, and — only
 * for a business that says it's VAT-registered — the VAT included in those prices at its country's rate.
 */
final readonly class TakingsSection
{
    public function __construct(private TenantContext $context, private Takings $takings, private Countries $countries) {}

    /** @return array{vat_registered: bool, vat_number: string|null} */
    public function settings(): array
    {
        $r = DB::connection($this->context->connectionName())->table('report_settings')->where('tenant_id', $this->context->id())->first();

        return ['vat_registered' => (bool) ($r->vat_registered ?? false), 'vat_number' => $r->vat_number ?? null];
    }

    public function saveSettings(bool $registered, ?string $number): void
    {
        DB::connection($this->context->connectionName())->table('report_settings')->updateOrInsert(['tenant_id' => $this->context->id()],
            ['vat_registered' => $registered, 'vat_number' => $registered ? $number : null, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function vatBp(): int
    {
        return (int) ($this->countries->find($this->context->current()->countryCode())['vat_bp'] ?? 0);
    }

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $now = $this->takings->net($p->from, $p->to, $p->branchIds);
        $before = $this->takings->net($p->previousFrom(), $p->previousTo(), $p->branchIds);
        $metrics = [['label' => 'Taken (after refunds)', 'value' => $now, 'previous' => $before, 'format' => 'money']];
        $bp = $this->vatBp();
        $vat = fn (int $gross) => intdiv($gross * $bp, 10000 + $bp); // VAT included in VAT-inclusive prices
        if ($this->settings()['vat_registered'] && $bp > 0) {
            $metrics[] = ['label' => 'VAT included ('.rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%)', 'value' => $vat($now), 'previous' => $vat($before), 'format' => 'money'];
            $metrics[] = ['label' => 'Taken excluding VAT', 'value' => $now - $vat($now), 'previous' => $before - $vat($before), 'format' => 'money'];
        }

        return ['title' => 'Takings and VAT', 'metrics' => $metrics, 'chart' => null, 'rows' => null, 'extra' => null, 'highlights' => [],
            'note' => $this->settings()['vat_registered'] ? 'VAT is worked out on prices that include it. Check with your accountant before filing.' : 'VAT is shown once you tell us you’re VAT-registered (Settings → VAT registration).'];
    }
}
