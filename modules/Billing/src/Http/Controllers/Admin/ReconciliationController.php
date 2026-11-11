<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Reconciliation\Reconciler;
use Wurk\Messaging\Contracts\DeliveryLog;

/**
 * Admin → Reconciliation (Sprint 15.5c): a provider's statement (CSV, any provider — decision B) against WURK's record.
 * The statement holds customers' numbers, so it's kept only briefly, encrypted, in the cache — never on disk — and
 * cleared on request; the run is logged with counts only.
 */
final class ReconciliationController
{
    private const MINUTES = 30;

    public function show(Request $request, DeliveryLog $log): Response
    {
        Gate::authorize('costs.manage');
        $upload = $this->upload($request);

        return Inertia::render('Billing::Admin/Reconciliation', [
            'drivers' => $log->drivers(),
            'upload' => $upload === null ? null : ['name' => $upload['name'], 'columns' => $upload['header'], 'sample' => array_slice($upload['rows'], 0, 3), 'rows' => count($upload['rows'])],
            'result' => $this->cached($request, 'result'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('costs.manage');
        $request->validate(['statement' => ['required', 'file', 'mimes:csv,txt', 'max:5120']], ['statement.mimes' => 'Upload the statement as a CSV file.']);
        $text = (string) file_get_contents((string) $request->file('statement')?->getRealPath());
        $text = (string) preg_replace('/^\x{FEFF}/u', '', $text);
        $first = strtok($text, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $file = new \SplTempFileObject;
        $file->fwrite($text);
        $file->rewind();
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD);
        $file->setCsvControl($delimiter);
        $rows = [];
        foreach ($file as $row) {
            if (is_array($row) && $row !== [null]) {
                $rows[] = array_map(fn ($v) => trim((string) $v), $row);
            }
        }
        $header = array_shift($rows) ?? [];
        if ($header === [] || $rows === []) {
            throw ValidationException::withMessages(['statement' => 'That file has no rows to check.']);
        }
        $token = Str::random(40);
        Cache::put('recon:'.$token.':upload', Crypt::encryptString((string) json_encode(['name' => (string) $request->file('statement')?->getClientOriginalName(), 'header' => $header, 'rows' => $rows])), now()->addMinutes(self::MINUTES));
        $request->session()->put('recon', $token);

        return back()->with('status', count($rows).' lines read. Choose the columns below.');
    }

    public function run(Request $request, Reconciler $reconciler, DeliveryLog $log, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('costs.manage');
        $upload = $this->upload($request) ?? throw ValidationException::withMessages(['statement' => 'Upload the statement again (it’s kept for '.self::MINUTES.' minutes).']);
        $n = count($upload['header']);
        $col = ['nullable', 'integer', 'min:0', 'max:'.($n - 1)];
        $d = $request->validate(['driver' => ['required', 'in:'.implode(',', $log->drivers() ?: ['none'])], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'id' => $col, 'number' => $col, 'date' => $col, 'cost' => $col]);
        if (! isset($d['id']) && (! isset($d['number']) || ! isset($d['date']))) {
            throw ValidationException::withMessages(['id' => 'Choose the message ID column, or both the number and date columns.']);
        }
        $result = $reconciler->run($upload['rows'], ['id' => $d['id'] ?? null, 'number' => $d['number'] ?? null, 'date' => $d['date'] ?? null, 'cost' => $d['cost'] ?? null],
            (string) $d['driver'], CarbonImmutable::parse((string) $d['from'], 'Africa/Johannesburg')->startOfDay(), CarbonImmutable::parse((string) $d['to'], 'Africa/Johannesburg')->endOfDay());
        $result += ['driver' => $d['driver'], 'from' => $d['from'], 'to' => $d['to']];
        Cache::put('recon:'.$request->session()->get('recon').':result', Crypt::encryptString((string) json_encode($result)), now()->addMinutes(self::MINUTES));
        $audit->record('billing.reconciliation', 'provider', (string) $d['driver'], ['from' => $d['from'], 'to' => $d['to'], 'statement' => $result['statement'], 'wurk' => $result['wurk'],
            'matched' => $result['matched'], 'statement_only' => $result['statementOnly'], 'wurk_only' => $result['wurkOnly']]); // counts only

        return back();
    }

    public function export(Request $request): HttpResponse
    {
        Gate::authorize('costs.manage');
        $r = $this->cached($request, 'result') ?? abort(404);
        $out = fopen('php://temp', 'r+') ?: abort(500);
        $safe = fn (string $v) => preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : $v;
        fputcsv($out, ['Where', 'Date', 'Provider message ID', 'Cost or business']);
        foreach ((array) $r['statementOnlyRows'] as $row) {
            fputcsv($out, array_map($safe, ['On the statement only', (string) $row[0], (string) $row[1], (string) $row[2]]));
        }
        foreach ((array) $r['wurkOnlyRows'] as $row) {
            fputcsv($out, array_map($safe, ['In WURK only', (string) $row[0], (string) $row[2], (string) $row[1]]));
        }
        rewind($out);

        return response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="reconciliation-'.$r['driver'].'-'.$r['from'].'-to-'.$r['to'].'.csv"']);
    }

    public function clear(Request $request): RedirectResponse
    {
        Gate::authorize('costs.manage');
        if (is_string($t = $request->session()->pull('recon'))) {
            Cache::forget('recon:'.$t.':upload');
            Cache::forget('recon:'.$t.':result');
        }

        return back()->with('status', 'Cleared.');
    }

    /** @return array{name: string, header: list<string>, rows: list<list<string>>}|null */
    private function upload(Request $request): ?array
    {
        /** @var array{name: string, header: list<string>, rows: list<list<string>>}|null */
        return $this->cached($request, 'upload');
    }

    /** @return array<string, mixed>|null */
    private function cached(Request $request, string $what): ?array
    {
        $t = $request->session()->get('recon');
        $v = is_string($t) ? Cache::get('recon:'.$t.':'.$what) : null;

        return is_string($v) ? (array) json_decode(Crypt::decryptString($v), true) : null;
    }
}
