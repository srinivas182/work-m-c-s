<?php

declare(strict_types=1);

namespace Wurk\Records\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Wurk\Bookings\Contracts\HealthPracticeStatus;
use Wurk\Records\Models\Document;
use Wurk\Records\Models\Patient;
use Wurk\Records\Support\PatientAccess;
use Wurk\Records\Support\Records;
use Wurk\Tenancy\Contracts\TenantContext;

/** Practice staff: patient records — patients, documents and versions, the private link, the access log, retention. */
final class RecordsController
{
    public function __construct(private TenantContext $context, private Records $records) {}

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $query = Patient::query()->orderBy('name');
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
        }

        return Inertia::render('Records::Index', [
            'health' => app(HealthPracticeStatus::class)->on(),
            'q' => $q,
            'patients' => $query->limit(100)->get()->map(fn (Patient $p) => $this->summary($p))->all(),
            'due' => Patient::query()->whereNotNull('flagged_at')->orderBy('flagged_at')->get()->map(fn (Patient $p) => $this->summary($p))->all(),
            'retentionYears' => $this->records->retentionYears(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->healthOnly();
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:160'], 'phone' => ['nullable', 'string', 'max:40']]);
        $p = Patient::query()->create($data);

        return redirect()->to(url()->current().'/'.$p->id)->with('status', 'Patient added.');
    }

    public function show(Request $request, string $patient): Response
    {
        $this->healthOnly();
        $p = Patient::query()->findOrFail($patient);
        $tz = $this->context->current()->timezone();

        return Inertia::render('Records::Patient', [
            'patient' => [...$this->summary($p), 'phone' => $p->phone],
            'documents' => Document::query()->where('patient_id', $p->id)->orderByDesc('created_at')->get()->map(fn (Document $d) => [
                'id' => $d->id, 'title' => $d->title, 'version' => $d->version, 'original_id' => $d->original_id, 'note' => $d->note,
                'by' => $d->uploaded_by, 'size' => $d->size, 'date' => $d->created_at->setTimezone($tz)->format('j M Y, H:i'),
            ])->all(),
            'log' => DB::connection($this->context->connectionName())->table('record_access_logs')->where('patient_id', $p->id)->orderByDesc('id')->limit(100)->get()
                ->map(fn ($l) => ['action' => $l->action, 'who' => $l->actor ?? ucfirst((string) $l->actor_type), 'at' => CarbonImmutable::parse((string) $l->created_at)->setTimezone($tz)->format('j M Y, H:i')])->all(),
            'canRemove' => Gate::allows('records.manage'),
        ]);
    }

    public function upload(Request $request, string $patient): RedirectResponse
    {
        $this->healthOnly();
        $p = Patient::query()->findOrFail($patient);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'], 'note' => ['nullable', 'string', 'max:500'], 'corrects' => ['nullable', 'string'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,txt'],
        ], ['file.mimes' => 'Upload a PDF, photo, Word document or text file.', 'file.max' => 'Files can be up to 20 MB.']);
        $corrects = empty($data['corrects']) ? null : Document::query()->where('patient_id', $p->id)->whereKey($data['corrects'])->firstOrFail();
        if ($corrects !== null && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => 'Say what the correction changes — the original is kept.']);
        }
        $this->records->upload($p, $data['title'], $data['file'], $this->who($request), $corrects, $data['note'] ?? null);

        return back()->with('status', $corrects === null ? 'Document added.' : 'Correction added — the original is kept.');
    }

    public function file(Request $request, string $patient, string $document): HttpResponse
    {
        $this->healthOnly();
        $doc = Document::query()->where('patient_id', $patient)->whereKey($document)->firstOrFail();
        $body = $this->records->read($doc, 'view', 'staff', $this->who($request), $request->ip());

        return new HttpResponse($body, 200, ['Content-Type' => $doc->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9 ._-]/', '', $doc->title).'"']);
    }

    public function sendLink(Request $request, string $patient): RedirectResponse
    {
        $this->healthOnly();
        try {
            app(PatientAccess::class)->sendLink(Patient::query()->findOrFail($patient), $this->who($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['link' => $e->getMessage()]);
        }

        return back()->with('status', 'Private link sent. The patient gets a one-time code when they open it.');
    }

    public function remove(Request $request, string $patient): RedirectResponse
    {
        Gate::authorize('records.manage');
        try {
            $this->records->remove(Patient::query()->findOrFail($patient), $this->who($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['remove' => $e->getMessage()]);
        }

        return redirect()->to(preg_replace('#/[^/]+/remove$#', '', url()->current()) ?? '/')->with('status', 'Records removed. The access log keeps a note that this happened.');
    }

    /** @return array<string, mixed> */
    private function summary(Patient $p): array
    {
        $tz = $this->context->current()->timezone();

        return ['id' => $p->id, 'name' => $p->name, 'email' => $p->email,
            'last' => $p->last_document_at?->setTimezone($tz)->format('j M Y'), 'keptUntil' => $this->records->keptUntil($p)?->setTimezone($tz)->format('j M Y'),
            'flagged' => $p->flagged_at !== null];
    }

    private function healthOnly(): void
    {
        abort_unless(app(HealthPracticeStatus::class)->on(), 404);
    }

    private function who(Request $request): string
    {
        $name = trim((string) data_get($request->user(), 'first_name').' '.(string) data_get($request->user(), 'last_name'));

        return $name !== '' ? $name : 'Staff';
    }
}
