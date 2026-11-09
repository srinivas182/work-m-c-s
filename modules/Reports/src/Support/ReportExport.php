<?php

declare(strict_types=1);

namespace Wurk\Reports\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Wurk\Platform\Support\ReportPeriod;

/** Reports as CSV or PDF (Sprint 15.1b): every figure with the previous period's, then each table. */
final class ReportExport
{
    /** @param list<array<string, mixed>> $sections */
    public function csv(array $sections): string
    {
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Could not build the file.');
        $safe = fn (string $v) => preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : $v; // no spreadsheet formulas
        fputcsv($out, ['Section', 'Figure', 'This period', 'Previous period']);
        foreach ($sections as $s) {
            foreach ((array) $s['metrics'] as $m) {
                fputcsv($out, [$safe((string) $s['title']), $safe((string) $m['label']), self::value($m, 'value'), ! empty($m['snapshot']) ? '' : self::value($m, 'previous')]);
            }
        }
        foreach ($sections as $s) {
            foreach (['rows', 'extra'] as $t) {
                if (! empty($s[$t]['data'])) {
                    fputcsv($out, []);
                    fputcsv($out, array_map($safe, (array) $s[$t]['columns']));
                    foreach ((array) $s[$t]['data'] as $r) {
                        fputcsv($out, array_map(fn ($v) => $safe((string) $v), (array) $r));
                    }
                }
            }
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<string>  $highlights
     */
    public function pdf(array $sections, array $highlights, string $business, ReportPeriod $period): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false); // never fetch anything while rendering
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        /** @var view-string $view */
        $view = 'reports::pdf';
        $dompdf->loadHtml(view($view, ['sections' => $sections, 'highlights' => $highlights, 'business' => $business,
            'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param array<string, mixed> $m */
    public static function value(array $m, string $key): string
    {
        $v = $m[$key] ?? 0;

        return match ($m['format'] ?? 'count') {
            'money' => 'R'.number_format(((int) $v) / 100, 2),
            'percent' => rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%',
            default => (string) $v,
        };
    }
}
