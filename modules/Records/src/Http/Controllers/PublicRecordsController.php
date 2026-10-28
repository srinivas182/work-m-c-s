<?php

declare(strict_types=1);

namespace Wurk\Records\Http\Controllers;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Wurk\Bookings\Contracts\HealthPracticeStatus;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Records\Models\Document;
use Wurk\Records\Models\RecordLink;
use Wurk\Records\Support\PatientAccess;
use Wurk\Records\Support\Records;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** A patient's own documents, on the practice's address: private link → one-time code → only their documents. */
final class PublicRecordsController
{
    /** Never cached, never indexed, never framed, no referrer. */
    private const HEADERS = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"];

    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private Entitlements $entitlements) {}

    public function show(Request $request, string $handle, string $token): Response
    {
        return $this->within($handle, function (CurrentTenant $tenant) use ($request, $token): Response {
            $access = app(PatientAccess::class);
            $link = $access->link($token);
            if ($link === null) {
                return $this->page($tenant, 'expired');
            }
            $patient = $access->patientFor($link, $request->cookies->get($this->cookie($link)));
            if ($patient === null) {
                return $this->page($tenant, 'code', ['token' => $token, 'sent' => $request->query('sent') === '1', 'error' => (string) $request->query('error', '')]);
            }
            $docs = Document::query()->where('patient_id', $patient->id)->orderByDesc('created_at')->get();
            $latest = $docs->groupBy(fn (Document $d) => $d->original_id ?? $d->id)->map->first();

            return $this->page($tenant, 'documents', ['token' => $token, 'patient' => $patient->name, 'docs' => $docs->map(fn (Document $d) => [
                'id' => $d->id, 'title' => $d->title, 'version' => $d->version, 'note' => $d->note, 'latest' => $latest->contains('id', $d->id),
                'date' => $d->created_at->setTimezone($tenant->timezone())->format('j M Y'),
            ])->all()]);
        });
    }

    public function sendCode(Request $request, string $handle, string $token): RedirectResponse
    {
        return $this->within($handle, function () use ($request, $token): RedirectResponse {
            $link = app(PatientAccess::class)->link($token);
            if ($link !== null) {
                app(PatientAccess::class)->sendCode($link, $request->ip());
            }

            return redirect("/records/{$token}?sent=1");
        });
    }

    public function verify(Request $request, string $handle, string $token): RedirectResponse
    {
        return $this->within($handle, function () use ($request, $token): RedirectResponse {
            $access = app(PatientAccess::class);
            $link = $access->link($token);
            if ($link === null) {
                return redirect("/records/{$token}");
            }
            if (! $access->verify($link, (string) $request->input('code'), $request->ip())) {
                $left = (int) config('records.code_attempts', 5) - $link->refresh()->attempts;

                return redirect("/records/{$token}?error=".($left > 0 ? 'wrong' : 'locked'));
            }
            $response = redirect("/records/{$token}");
            $response->headers->setCookie(Cookie::create($this->cookie($link), $access->session($link), now()->addMinutes((int) config('records.session_minutes', 30)),
                "/records/{$token}", null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT));

            return $response;
        });
    }

    public function file(Request $request, string $handle, string $token, string $document): Response
    {
        return $this->within($handle, function () use ($request, $token, $document): Response {
            $access = app(PatientAccess::class);
            $link = $access->link($token) ?? abort(404);
            $patient = $access->patientFor($link, $request->cookies->get($this->cookie($link))) ?? abort(403);
            $doc = Document::query()->where('patient_id', $patient->id)->whereKey($document)->firstOrFail(); // only their own
            $download = $request->query('download') === '1';
            $body = app(Records::class)->read($doc, $download ? 'download' : 'view', 'patient', $patient->name, $request->ip());
            $name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $doc->title).'.'.(explode('/', $doc->mime)[1] ?? 'bin');

            return new Response($body, 200, [...self::HEADERS, 'Content-Type' => $doc->mime, 'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"']);
        });
    }

    private function cookie(RecordLink $link): string
    {
        return 'rec_'.substr($link->token_hash, 0, 16);
    }

    /** @param  array<string, mixed>  $data */
    private function page(CurrentTenant $tenant, string $state, array $data = []): Response
    {
        return new Response(view('records::public.records', ['business' => $tenant->displayName(), 'state' => $state, ...$data])->render(), $state === 'expired' ? 410 : 200, self::HEADERS);
    }

    /** Only for an active health practice whose package includes bookings. */
    private function within(string $handle, Closure $fn): mixed
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! $tenant->isActive() || ! $this->entitlements->entitled($tenant->tenantId(), 'bookings')) {
            abort(404);
        }

        return $this->context->run($tenant, function () use ($tenant, $fn) {
            abort_unless(app(HealthPracticeStatus::class)->on(), 404);

            return $fn($tenant);
        });
    }
}
