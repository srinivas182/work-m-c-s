<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Billing\Models\CreditNote;
use Wurk\Billing\Models\Invoice;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\BillingSettings;
use Wurk\Billing\Support\Dunning;
use Wurk\Billing\Support\Invoices;
use Wurk\Billing\Support\Mandates;
use Wurk\Billing\Support\Payments;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** WURK staff: invoice settings, a business's invoices and payments, and the failed-payment queue. */
final class BillingAdminController
{
    public function __construct(private readonly BillingSettings $settings, private readonly TenantDirectory $tenants, private readonly Catalog $catalog, private readonly Dunning $dunning) {}

    public function settings(): Response
    {
        Gate::authorize('invoices.manage');

        return Inertia::render('Billing::Admin/InvoiceSettings', ['issuer' => $this->settings->issuer(), 'placeholder' => $this->settings->issuerIsPlaceholder(), 'dunning' => $this->settings->dunning()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        Gate::authorize('invoices.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'vat_number' => ['required', 'regex:/^4\d{9}$/'],
            'registration' => ['nullable', 'string', 'max:30'],
            'retry_days' => ['required', 'array', 'min:1', 'max:5'],
            'retry_days.*' => ['integer', 'between:1,30'],
            'restrict_from_day' => ['required', 'integer', 'between:1,30'],
            'suspend_on_day' => ['required', 'integer', 'between:2,60', 'gt:restrict_from_day'],
        ], ['vat_number.regex' => 'South African VAT numbers have 10 digits and start with 4.']);
        $by = (string) $request->user()?->getAuthIdentifier();

        $this->settings->setIssuer(['name' => $data['name'], 'address' => $data['address'], 'vat_number' => $data['vat_number'], 'registration' => (string) ($data['registration'] ?? '')], $by);
        $days = array_values(array_unique(array_map('intval', $data['retry_days'])));
        sort($days);
        $this->settings->setDunning(['retry_days' => $days, 'restrict_from_day' => (int) $data['restrict_from_day'], 'suspend_on_day' => (int) $data['suspend_on_day']], $by);

        return back()->with('status', 'Invoice and dunning settings saved. Invoices already issued keep their original details.');
    }

    public function business(string $tenant): Response
    {
        Gate::authorize('invoices.view');
        $business = $this->tenants->find($tenant) ?? abort(404);

        return Inertia::render('Billing::Admin/BusinessBilling', [
            'business' => ['id' => $tenant, 'name' => $business->displayName()],
            'subscription' => $this->catalog->subscriptionOf($tenant),
            'stage' => $this->dunning->stage($tenant),
            'day' => $this->dunning->day($tenant),
            'canManage' => Gate::allows('invoices.manage'),
            'invoices' => Invoice::query()->where('tenant_id', $tenant)->latest('issued_at')->get()->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'issued_at' => $i->issued_at->toIso8601String(), 'total_cents' => $i->total_cents, 'credited_cents' => $i->credited_cents,
                'credit_notes' => CreditNote::query()->where('invoice_id', $i->id)->get(['id', 'number', 'total_cents', 'refund', 'refund_status'])->toArray(),
            ])->all(),
            'payments' => Payment::query()->where('tenant_id', $tenant)->latest()->limit(30)->get()
                ->map(fn (Payment $p) => ['id' => $p->id, 'at' => $p->created_at?->toIso8601String(), 'purpose' => $p->purpose, 'gateway' => $p->gateway, 'total_cents' => $p->total_cents, 'status' => $p->status, 'failure' => $p->failure])->all(),
        ]);
    }

    public function credit(Request $request, string $tenant, string $invoice, Invoices $invoices): RedirectResponse
    {
        Gate::authorize('invoices.manage');
        $model = Invoice::query()->where('tenant_id', $tenant)->findOrFail($invoice);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01'], 'reason' => ['required', 'string', 'min:5', 'max:255']]);
        $note = $invoices->credit($model, isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : null, (string) $data['reason'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', "Credit note {$note->number} issued".($note->refund === 'eft' ? ' — refund by EFT is waiting for finance.' : '.'));
    }

    public function markRefunded(string $tenant, string $note): RedirectResponse
    {
        Gate::authorize('invoices.manage');
        CreditNote::query()->where('tenant_id', $tenant)->where('refund', 'eft')->findOrFail($note)->update(['refund_status' => 'done']);

        return back()->with('status', 'EFT refund marked as paid.');
    }

    public function retry(string $tenant, Payments $payments, Mandates $mandates): RedirectResponse
    {
        Gate::authorize('invoices.manage');
        $sub = $this->catalog->subscriptionOf($tenant) ?? abort(404);
        abort_unless(in_array($sub['status'], ['past_due', 'suspended'], true), 422);

        $details = ['package' => $sub['package_key'], 'period' => $sub['period'], 'renewal_of' => $sub['current_period_end'], 'manual_retry' => true];
        $mandate = $mandates->active($tenant);
        $payment = $mandate !== null ? $mandates->collect($mandate, $tenant, $sub['period_cents'], $details) : $payments->chargeSaved($tenant, 'subscription', $sub['period_cents'], $details);

        return back()->with($payment->status === 'failed' ? 'warning' : 'status', match ($payment->status) {
            'succeeded' => 'Payment taken — the business is active again.',
            'pending' => 'Debit order submitted; the bank confirms in a few days.',
            default => 'Retry failed: '.$payment->failure,
        });
    }

    public function queue(): Response
    {
        Gate::authorize('invoices.view');
        $rows = [];
        foreach ($this->catalog->inDunning() as $tenantId => $state) {
            $last = Payment::query()->where('tenant_id', $tenantId)->where('status', 'failed')->latest()->first();
            $rows[] = [
                'id' => $tenantId, 'name' => $this->tenants->find($tenantId)?->displayName() ?? $tenantId,
                'stage' => $this->dunning->stage($tenantId), 'day' => $this->dunning->day($tenantId), 'since' => $state['since'],
                'last_failure' => $last?->failure,
            ];
        }
        usort($rows, fn ($a, $b) => ($b['day'] ?? 0) <=> ($a['day'] ?? 0));

        return Inertia::render('Billing::Admin/FailedPayments', ['rows' => $rows, 'canManage' => Gate::allows('invoices.manage')]);
    }

    public function download(string $tenant, string $invoice, Invoices $invoices): HttpResponse
    {
        Gate::authorize('invoices.view');
        $model = Invoice::query()->where('tenant_id', $tenant)->findOrFail($invoice);

        return new HttpResponse($invoices->pdf($model), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$model->number.'.pdf"']);
    }
}
