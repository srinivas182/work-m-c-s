<?php

declare(strict_types=1);

namespace Wurk\Reports\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Wurk\Platform\Support\ReportCsv;
use Wurk\Platform\Support\ReportPeriod;

/** Reports as CSV or PDF (Sprint 15.1b): every figure with the previous period's, then each table. */
final class ReportExport
{
    /** @param list<array<string, mixed>> $sections */
    public function csv(array $sections): string
    {
        return ReportCsv::csv($sections);
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
        return ReportCsv::value($m, $key);
    }
}
