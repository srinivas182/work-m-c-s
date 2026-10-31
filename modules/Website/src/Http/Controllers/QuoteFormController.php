<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Mail\EnquiryMail;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Uploads\FormFiles;

/**
 * Public quote form submissions (no session, no CSRF: there is no signed-in state to
 * protect). Spam is handled by a hidden field, per-visitor limits and a daily cap
 * per business; consent is required; the visitor's IP is kept only as a hash.
 */
final class QuoteFormController
{
    public const DAILY_CAP = 200;

    public function __invoke(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context): Response
    {
        $tenant = $tenants->findByHandle($handle) ?? abort(404);

        // Bots fill in every field; people never see this one. Pretend it worked.
        if (trim((string) $request->input('website', '')) !== '') {
            return $this->thanks($tenant->displayName());
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{6,20}$/', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:120', 'required_without:phone'],
            'service' => ['nullable', 'string', 'max:80'],
            'form' => ['nullable', 'in:quote,enquiry,waiting_list,interest'],
            'message' => [$request->input('form', 'quote') === 'quote' ? 'required' : 'nullable', 'string', 'max:2000'], // only quote forms require it
            'files' => ['nullable', 'array', 'max:'.FormFiles::MAX_FILES],
            'files.*' => ['file', 'max:'.FormFiles::MAX_KB],
            'answers' => ['nullable', 'array', 'max:15'],
            'answers.*.q' => ['required', 'string', 'max:120'],
            'answers.*.a' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
            'news' => ['nullable'],        // optional, unticked: marketing consent only when ticked
            'lead' => ['nullable', 'in:0,1'], // the form's “open a lead for each reply” setting
        ], [
            'phone.required_without' => 'Give a phone number or an email so the business can reply.',
            'email.required_without' => 'Give a phone number or an email so the business can reply.',
            'consent.accepted' => 'Please agree to be contacted about your request.',
        ]);

        $capKey = 'quote-cap:'.$tenant->tenantId().':'.now()->format('Y-m-d');
        if (RateLimiter::tooManyAttempts($capKey, self::DAILY_CAP)) {
            abort(429);
        }
        RateLimiter::hit($capKey, 86400);

        $notify = $context->run($tenant, function () use ($data, $request): ?string {
            // Every file is checked before anything is saved: a bad file refuses the whole reply, clearly.
            $files = array_values(array_filter((array) $request->file('files', []), fn ($f) => $f instanceof UploadedFile));
            foreach ($files as $f) {
                if (($problem = app(FormFiles::class)->problem($f)) !== null) {
                    throw ValidationException::withMessages(['files' => $problem]);
                }
            }
            $news = $request->boolean('news');
            $answers = array_values(array_filter(array_map(fn (array $x) => ['q' => trim((string) $x['q']), 'a' => trim((string) ($x['a'] ?? ''))], $data['answers'] ?? []), fn (array $x) => $x['a'] !== ''));
            $enquiry = Enquiry::query()->create([
                'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
                'form' => $data['form'] ?? 'quote', 'service' => $data['service'] ?? null, 'message' => $data['message'] ?? '', 'consented_at' => now(),
                'marketing_consent_at' => $news ? now() : null, 'answers' => $answers === [] ? null : $answers,
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), 'created_at' => now(),
            ]);
            // The CRM: the person becomes (or joins) a contact; quote forms open a lead by default.
            $kind = $data['form'] ?? 'quote';
            $what = match (true) {
                $kind === 'waiting_list' => 'Waiting list: '.$data['name'],
                $kind === 'interest' => 'Registration of interest: '.$data['name'],
                ($data['service'] ?? null) !== null => 'Quote request: '.$data['service'],
                default => 'Website enquiry',
            };
            $note = trim(implode("\n", [...array_map(fn (array $x) => $x['q'].': '.$x['a'], $answers), (string) ($data['message'] ?? '')]));
            $contact = app(ContactBook::class)->remember($data['name'], $data['phone'] ?? null, $data['email'] ?? null, 'form', $enquiry->id, null, $what, $news ? 'Website form' : null);
            $lead = ($data['lead'] ?? '0') === '1'
                ? app(LeadBook::class)->open($contact, $what, $kind === 'quote' && ($data['service'] ?? null) !== null ? 'quote' : 'form', null, null, $note === '' ? null : $note)
                : null;
            $enquiry->update(['contact_id' => $contact, 'lead_id' => $lead]);
            foreach ($files as $f) {
                app(FormFiles::class)->store($f, $enquiry->id); // encrypted; held until scanned clean
            }

            return Site::query()->first()?->settings['enquiry_email'] ?? null;
        });

        if (is_string($notify) && $notify !== '') {
            Mail::to($notify)->send(new EnquiryMail($tenant->displayName(), $data));
        }

        return $this->thanks($tenant->displayName());
    }

    private function thanks(string $business): Response
    {
        return new Response(view('website::enquiry-thanks', ['business' => $business])->render());
    }
}
