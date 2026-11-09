<?php

declare(strict_types=1);

namespace Wurk\Reports\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Reports\Mail\ReportSummaryMail;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * The owners' summary email (Sprint 15.1b): weekly (Mondays, for the last 7 days) by default, monthly (the 1st, for the
 * month before), or off. Sent once per period, on the business's own calendar, to its owners.
 */
final readonly class ReportSummaries
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private BusinessReports $reports) {}

    public function setting(): string
    {
        return (string) (DB::connection($this->context->connectionName())->table('report_settings')->where('tenant_id', $this->context->id())->value('summary') ?? 'weekly');
    }

    public function save(string $summary): void
    {
        DB::connection($this->context->connectionName())->table('report_settings')->updateOrInsert(['tenant_id' => $this->context->id()], ['summary' => $summary, 'updated_at' => now()]);
    }

    /** Daily: send what's due. @return int businesses emailed */
    public function sendDue(): int
    {
        $sent = 0;
        foreach ($this->tenants->activeIds() as $id) {
            $tenant = $this->tenants->find($id);
            if ($tenant === null || $tenant->isDemo()) {
                continue;
            }
            $sent += (int) $this->context->run($tenant, fn (): bool => $this->sendIfDue());
        }

        return $sent;
    }

    private function sendIfDue(): bool
    {
        $setting = $this->setting();
        $local = CarbonImmutable::now($this->context->current()->timezone());
        if ($local->hour < 7) {
            return false; // in the morning, the business's time
        }
        $today = $local->startOfDay();
        [$from, $span] = match (true) {
            $setting === 'weekly' && $today->isMonday() => [$today->subDays(7), 'weekly summary'],
            $setting === 'monthly' && $today->day === 1 => [$today->subMonth(), 'monthly summary'],
            default => [null, ''],
        };
        $db = DB::connection($this->context->connectionName());
        if ($from === null || $db->table('report_settings')->where('tenant_id', $this->context->id())->value('summary_sent_for') === $today->toDateString()) {
            return false;
        }
        $owners = app(TeamDirectory::class)->emailsWithPermission('billing.manage');
        if ($owners === []) {
            return false;
        }
        $stored = (string) config('app.timezone');
        $period = new ReportPeriod($from->setTimezone($stored), $today->setTimezone($stored), null, forOwners: true);
        $sections = $this->reports->sections($period);
        $highlights = array_values(array_merge(...array_map(fn ($s) => (array) ($s['highlights'] ?? []), $sections ?: [[]])));
        $lines = array_values(array_map(fn ($s) => ['title' => (string) $s['title'], 'lines' => array_values(array_map(fn ($m) => $m['label'].': '.ReportExport::value($m, 'value')
            .(empty($m['snapshot']) ? ' (was '.ReportExport::value($m, 'previous').')' : ''), (array) $s['metrics']))], $sections));
        $link = route('tenant.reports', ['tenant' => $this->context->current()->handle()]);
        foreach ($owners as $email) {
            Mail::to($email)->send(new ReportSummaryMail($this->context->current()->displayName(), $span, $highlights, $lines, $link));
        }
        $db->table('report_settings')->updateOrInsert(['tenant_id' => $this->context->id()], ['summary_sent_for' => $today->toDateString(), 'updated_at' => now()]);

        return true;
    }
}
