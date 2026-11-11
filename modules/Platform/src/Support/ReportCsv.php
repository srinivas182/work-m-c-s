<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

/** A report as CSV (Sprint 15.1b; shared with Admin → Metrics in 15.4b): every figure with the previous period's, then each table. */
final class ReportCsv
{
    /** @param list<array<string, mixed>> $sections */
    public static function csv(array $sections): string
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
