<?php

declare(strict_types=1);

namespace Wurk\Data\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Data\Mail\DataMail;
use Wurk\Data\Models\DataExport;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Tenancy\Contracts\TenantContext;
use ZipArchive;

/**
 * Builds a business's full export (Sprint 16.6): README, one CSV per area, then the files — only the parts the person
 * who asked may see (health records only with records.view). Secrets never leave: any column that looks like a
 * token, hash, secret, password or key is left out. The ZIP is kept for 24 hours.
 */
final readonly class Exporter
{
    public const HOURS = 24;

    private const SECRET_COLUMNS = '/(^tenant_id$|token|hash|secret|password|api_key|credentials|private)/i';

    public function __construct(private DataExports $exports, private TenantContext $context, private Gate $gate, private PeopleDirectory $people, private AuditLog $audit) {}

    public function build(DataExport $export): void
    {
        $user = Auth::guard()->getProvider()->retrieveById($export->requested_by);
        $may = fn (?string $permission) => $permission === null || ($user instanceof Authenticatable && $this->gate->forUser($user)->allows($permission));
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'wurk-export');
        $temps = [];
        $contents = ['tables' => [], 'files' => []];
        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not start the ZIP file.');
            }
            foreach ($this->exports->tables() as $key => $t) {
                if (! $may($t['permission'])) {
                    continue;
                }
                [$csv, $count] = $this->csv($t['rows']);
                $temps[] = $csv;
                $zip->addFile($csv, "{$key}.csv");
                $contents['tables'][$t['label']] = $count;
            }
            foreach ($this->exports->fileSets() as $key => $set) {
                if (! $may($set['permission'])) {
                    continue;
                }
                $n = 0;
                foreach (($set['files'])() as $f) {
                    $zip->addFromString("files/{$key}/".ltrim(str_replace('..', '', $f['path']), '/'), ($f['contents'])());
                    $n++;
                }
                $contents['files'][$set['label']] = $n;
            }
            $zip->addFromString('README.txt', $this->readme($contents));
            $zip->close();
            $path = "exports/{$export->id}.zip";
            $stream = fopen($zipPath, 'r');
            if ($stream === false) {
                throw new RuntimeException('Could not read the ZIP file.');
            }
            $this->context->disk()->writeStream($path, $stream);
            fclose($stream);
            $export->update(['status' => 'ready', 'path' => $path, 'size' => (int) filesize($zipPath), 'contents' => $contents, 'ready_at' => now(), 'expires_at' => now()->addHours(self::HOURS)]);
            $this->tell($export);
        } catch (Throwable $e) {
            $export->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);
            report($e);
        } finally {
            foreach ([$zipPath, ...$temps] as $f) {
                @unlink($f);
            }
        }
    }

    /** The 24-hour download link: signed, and still for signed-in people allowed to export. */
    public function link(DataExport $export): string
    {
        return URL::temporarySignedRoute('tenant.data.download', $export->expires_at ?? now()->addHours(self::HOURS), ['tenant' => $this->context->current()->handle(), 'export' => $export->id]);
    }

    /** @return array{string, int} temp CSV path and row count */
    private function csv(\Closure $rows): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wurk-csv');
        $out = fopen($path, 'w');
        if ($out === false) {
            throw new RuntimeException('Could not write a CSV.');
        }
        fwrite($out, "\xEF\xBB\xBF"); // so spreadsheets read accents correctly
        $columns = null;
        $count = 0;
        foreach ($rows() as $row) {
            $row = array_filter((array) $row, fn ($k) => preg_match(self::SECRET_COLUMNS, (string) $k) !== 1, ARRAY_FILTER_USE_KEY);
            if ($columns === null) {
                $columns = array_keys($row);
                fputcsv($out, $columns, escape: '');
            }
            fputcsv($out, array_map(fn ($c) => $this->cell($row[$c] ?? null), $columns), escape: '');
            $count++;
        }
        fclose($out);

        return [$path, $count];
    }

    private function cell(mixed $v): string
    {
        $s = match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            is_scalar($v) => (string) $v,
            default => (string) json_encode($v),
        };

        // Spreadsheets run cells starting with = + - @ as formulas: keep them as text.
        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'".$s : $s;
    }

    /** @param array{tables: array<string, int>, files: array<string, int>} $contents */
    private function readme(array $contents): string
    {
        $lines = ['Your data from WURK — '.$this->context->current()->displayName(), 'Exported '.now($this->context->current()->timezone())->format('j F Y H:i'), '',
            'TABLES (one CSV each; open them in Excel, Google Sheets or Numbers):'];
        foreach ($contents['tables'] as $label => $n) {
            $lines[] = "- {$label}: {$n} ".($n === 1 ? 'row' : 'rows');
        }
        $lines[] = '';
        $lines[] = 'FILES (in the files folder):';
        foreach ($contents['files'] as $label => $n) {
            $lines[] = "- {$label}: {$n} ".($n === 1 ? 'file' : 'files');
        }
        if ($contents['files'] === []) {
            $lines[] = '- None.';
        }
        $lines[] = '';
        $lines[] = 'Keep this file safe: it holds your customers’ personal information.';

        return implode("\n", $lines)."\n";
    }

    private function tell(DataExport $export): void
    {
        $email = $this->people->contacts([$export->requested_by])[$export->requested_by]['email'] ?? null;
        $this->audit->record('data.export_ready', 'data_export', $export->id, ['size' => $export->size]);
        if ($email !== null) {
            Mail::to($email)->send(new DataMail('Your WURK data export is ready', ['Your full export of '.$this->context->current()->displayName().' is ready.',
                'The link works for '.self::HOURS.' hours, and only when you’re signed in.'], $this->link($export)));
        }
    }
}
