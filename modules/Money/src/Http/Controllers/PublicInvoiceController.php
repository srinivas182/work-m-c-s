<?php

declare(strict_types=1);

namespace Wurk\Money\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Wurk\Money\Models\Invoice;
use Wurk\Money\Support\Invoices;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Platform\Contracts\BusinessBadge;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** The customer's invoice page on the business's own address (23.5a): signed links only; pay online or by EFT. */
final class PublicInvoiceController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context) {}

    public function show(Request $request, string $handle, string $invoice): Response
    {
        return $this->within($handle, fn (CurrentTenant $t) => $this->page($t, Invoice::query()->findOrFail($invoice), null));
    }

    public function pay(Request $request, string $handle, string $invoice): RedirectResponse|Response
    {
        return $this->within($handle, function (CurrentTenant $t) use ($invoice) {
            $model = Invoice::query()->findOrFail($invoice);
            $back = URL::signedRoute('money.public.returned', ['handle' => $t->handle(), 'invoice' => $model->id]);
            $url = app(Invoices::class)->pay($model, $back);

            return $url === null ? $this->page($t, $model, 'Online payment isn’t available right now. Pay by EFT, or ask the business.') : redirect()->away($url);
        });
    }

    /** Back from paying: checked with the provider before it counts. */
    public function returned(Request $request, string $handle, string $invoice): Response
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['reference', 'trxref', 'lang']), 403); // the provider adds its reference to our signed link

        return $this->within($handle, function (CurrentTenant $t) use ($invoice) {
            $model = Invoice::query()->findOrFail($invoice);
            $model = app(Invoices::class)->confirm((string) $model->payment_reference) ?? $model;

            return $this->page($t, $model, $model->status === 'paid' ? 'Thank you — your payment is in.' : 'We’re still confirming your payment. This page updates when it does.');
        });
    }

    private function page(CurrentTenant $t, Invoice $i, ?string $message): Response
    {
        $rand = fn (int $c) => Invoices::rand($c);
        $eft = app(BusinessPayments::class)->eftInstructions();

        return new Response(view('money::public.invoice', [
            'business' => $t->displayName(), 'badge' => app()->bound(BusinessBadge::class) ? app(BusinessBadge::class)->for($t->tenantId()) : null, 'label' => $i->label(), 'customer' => $i->customer_name, 'issued' => $i->issued_on->format('j M Y'), 'due' => $i->due_on->format('j M Y'),
            'vatNumber' => $i->vat_number, 'paid' => $i->status === 'paid', 'void' => $i->status === 'void', 'paidOn' => $i->paid_at?->setTimezone($t->timezone())->format('j M Y'),
            'lines' => array_map(fn (array $l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'amount' => $rand($l['quantity'] * $l['unit_cents'])], $i->lines),
            'vat' => $i->vat_bp > 0 ? $rand($i->vat_cents) : null, 'vatRate' => rtrim(rtrim(number_format($i->vat_bp / 100, 2, '.', ''), '0'), '.'), 'total' => $rand($i->total_cents), 'message' => $message,
            'refunded' => (int) $i->refunded_cents > 0 ? $rand((int) $i->refunded_cents) : null,
            'refundedVat' => (int) $i->refunded_cents > 0 && $i->vat_bp > 0 ? $rand(Invoices::refundVat($i, (int) $i->refunded_cents)) : null,
            'canPayOnline' => app(BusinessPayments::class)->available() !== [], 'eft' => $eft,
            'payUrl' => URL::signedRoute('money.public.pay', ['handle' => $t->handle(), 'invoice' => $i->id]),
        ])->render(), 200, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** Only for an active business. */
    private function within(string $handle, \Closure $fn): mixed
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! $tenant->isActive()) {
            abort(404);
        }

        return $this->context->run($tenant, fn () => $fn($tenant));
    }
}
