<?php

declare(strict_types=1);

namespace Wurk\Ai\Support;

use Carbon\CarbonImmutable;
use Wurk\Platform\Contracts\AssistantReadTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Support\ReportCsv;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * "Ask WURK" reads the business's own reports (15.1): every section this person may see — sales, bookings, customers,
 * takings, website, referrals — for a period, with the period before for comparison. Totals and top lists only.
 */
final readonly class FiguresTool implements AssistantReadTool
{
    public const PERIODS = ['today', 'yesterday', 'tomorrow', 'this_week', 'last_week', 'next_week', 'this_month', 'last_month', 'last_7_days', 'last_30_days', 'this_quarter', 'this_year'];

    public function __construct(private BusinessReports $reports, private TenantContext $context) {}

    public function name(): string
    {
        return 'business_figures';
    }

    public function description(): string
    {
        return 'Look up the business’s own figures for a period: sales and best sellers, bookings and no-shows, customers, takings and VAT, '
            .'website visits, campaigns, referrals — each compared with the period before.';
    }

    public function parameters(): array
    {
        return [
            'period' => 'one of '.implode(', ', self::PERIODS).' — or leave out and give from/to',
            'from' => 'first day, YYYY-MM-DD (only with to)',
            'to' => 'last day, YYYY-MM-DD',
        ];
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function entitlement(): ?string
    {
        return null; // each section checks its own feature
    }

    public function read(array $params): string
    {
        [$first, $last] = $this->days($params);
        $period = ReportPeriod::between($first, $last, $this->context->current()->timezone())
            ?? throw new AssistantRefused('I can look at up to two years at a time.');
        $lines = ["Period: {$first} to {$last} (\"before\" = the same length just before it)"];
        foreach ($this->reports->sections($period) as $s) {
            $lines[] = '## '.(string) ($s['title'] ?? $s['key']);
            foreach ((array) ($s['metrics'] ?? []) as $m) {
                $lines[] = '- '.$m['label'].': '.ReportCsv::value($m, 'value').' (before: '.ReportCsv::value($m, 'previous').')';
            }
            foreach (['rows', 'extra'] as $table) {
                $t = $s[$table] ?? null;
                if (is_array($t) && ($t['data'] ?? []) !== []) {
                    $lines[] = implode(' | ', (array) $t['columns']);
                    foreach (array_slice((array) $t['data'], 0, 8) as $row) {
                        $lines[] = implode(' | ', array_map('strval', (array) $row));
                    }
                }
            }
            foreach ((array) ($s['highlights'] ?? []) as $h) {
                $lines[] = '* '.$h;
            }
        }

        return count($lines) === 1 ? 'No figures are available to this person for that period.' : implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{string, string}
     */
    private function days(array $params): array
    {
        $tz = $this->context->current()->timezone();
        $from = is_string($params['from'] ?? null) ? $params['from'] : null;
        $to = is_string($params['to'] ?? null) ? $params['to'] : null;
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && $to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            return [$from, $to];
        }
        $today = CarbonImmutable::now($tz)->startOfDay();
        [$a, $b] = match ((string) ($params['period'] ?? 'this_month')) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'tomorrow' => [$today->addDay(), $today->addDay()],
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()],
            'last_week' => [$today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()],
            'next_week' => [$today->addWeek()->startOfWeek(), $today->addWeek()->endOfWeek()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };

        return [$a->toDateString(), $b->toDateString()];
    }
}
