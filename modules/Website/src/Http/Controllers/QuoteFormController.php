<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Mail\EnquiryMail;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Models\Site;

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
            'message' => ['required', 'string', 'max:2000'],
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
            $news = $request->boolean('news');
            $enquiry = Enquiry::query()->create([
                'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
                'service' => $data['service'] ?? null, 'message' => $data['message'], 'consented_at' => now(),
                'marketing_consent_at' => $news ? now() : null,
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), 'created_at' => now(),
            ]);
            // The CRM: the person becomes (or joins) a contact; quote forms open a lead by default.
            $what = ($data['service'] ?? null) !== null ? 'Quote request: '.$data['service'] : 'Website enquiry';
            $contact = app(ContactBook::class)->remember($data['name'], $data['phone'] ?? null, $data['email'] ?? null, 'form', $enquiry->id, null, $what, $news ? 'Website form' : null);
            $lead = ($data['lead'] ?? '0') === '1'
                ? app(LeadBook::class)->open($contact, $what, ($data['service'] ?? null) !== null ? 'quote' : 'form', null, null, $data['message'])
                : null;
            $enquiry->update(['contact_id' => $contact, 'lead_id' => $lead]);

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
