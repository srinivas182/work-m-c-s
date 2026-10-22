<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Gateways\PaymentGateway;
use Wurk\Billing\Mail\CreditNoteMail;
use Wurk\Billing\Mail\InvoiceMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditNote;
use Wurk\Billing\Models\Invoice;
use Wurk\Billing\Models\InvoiceLine;
use Wurk\Billing\Models\Payment;

/** SARS tax invoices (one per payment), credit notes and refunds. */
final readonly class Invoices
{
    public function __construct(private BillingSettings $settings, private PaymentGateway $gateway, private AuditLog $audit) {}

    /** Issues the tax invoice for a successful payment (once). */
    public function forPayment(Payment $payment): ?Invoice
    {
        if ($payment->purpose === 'card_check') {
            return null; // the R1 card check is refunded at once: nothing was supplied
        }

        $invoice = DB::transaction(function () use ($payment): ?Invoice {
            if (Invoice::query()->where('payment_id', $payment->id)->exists()) {
                return null;
            }
            $account = BillingAccount::query()->find($payment->tenant_id);

            $invoice = Invoice::query()->create([
                'number' => $this->nextNumber('invoice', 'WURK-INV-'),
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'issued_at' => $payment->paid_at ?? now(),
                'issuer' => $this->settings->issuer(),
                'customer' => [
                    'name' => (string) ($account->billing_name ?? ''), 'email' => (string) ($account->billing_email ?? ''),
                    'address' => $account?->address, 'vat_number' => $account?->vat_number,
                ],
                'currency' => $payment->currency, 'vat_bp' => $payment->vat_bp,
                'excl_cents' => $payment->amount_excl_cents, 'vat_cents' => $payment->vat_cents, 'total_cents' => $payment->total_cents,
            ]);
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id, 'description' => self::describe($payment), 'quantity' => 1,
                'excl_cents' => $payment->amount_excl_cents, 'vat_cents' => $payment->vat_cents, 'total_cents' => $payment->total_cents,
            ]);

            return $invoice;
        });

        if ($invoice !== null) {
            $this->audit->record('invoice.issued', 'invoice', $invoice->id, ['number' => $invoice->number, 'tenant_id' => $invoice->tenant_id, 'total' => $invoice->total_cents / 100], ['platform' => true]);
            if ($invoice->customer['email'] !== '') {
                Mail::to($invoice->customer['email'])->send(new InvoiceMail($invoice, $this->pdf($invoice)));
            }
        }

        return $invoice;
    }

    /** A credit note against an invoice (full when $exclCents is null); refunds the card where possible. */
    public function credit(Invoice $invoice, ?int $exclCents, string $reason, ?string $by): CreditNote
    {
        $note = DB::transaction(function () use ($invoice, $exclCents, $reason, $by): CreditNote {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $remainingTotal = $locked->total_cents - $locked->credited_cents;
            $excl = $exclCents ?? (int) round($remainingTotal * 10000 / (10000 + $locked->vat_bp));
            $vat = $exclCents === null ? $remainingTotal - $excl : intdiv($excl * $locked->vat_bp + 5000, 10000);
            if ($excl <= 0 || $excl + $vat > $remainingTotal) {
                throw ValidationException::withMessages(['amount' => 'That is more than is left to credit on this invoice.']);
            }

            $payment = $locked->payment_id === null ? null : Payment::query()->find($locked->payment_id);
            $refund = match ($payment?->gateway) {
                'paystack' => 'card',
                'cash', 'debicheck' => 'eft',
                default => 'none',
            };

            $note = CreditNote::query()->create([
                'number' => $this->nextNumber('credit_note', 'WURK-CN-'), 'invoice_id' => $locked->id, 'tenant_id' => $locked->tenant_id,
                'issued_at' => now(), 'reason' => $reason, 'excl_cents' => $excl, 'vat_cents' => $vat, 'total_cents' => $excl + $vat,
                'refund' => $refund, 'refund_status' => $refund === 'none' ? 'not_needed' : 'pending', 'issued_by' => $by,
            ]);
            $locked->update(['credited_cents' => $locked->credited_cents + $excl + $vat]);

            return $note;
        });

        if ($note->refund === 'card') {
            $payment = Payment::query()->findOrFail($invoice->payment_id);
            if ($this->gateway->refund($payment->reference, $note->total_cents)) {
                $note->update(['refund_status' => 'done']);
            }
        }
        if ($invoice->customer['email'] !== '') {
            Mail::to($invoice->customer['email'])->send(new CreditNoteMail($note, $this->creditNotePdf($note, $invoice)));
        }
        $this->audit->record('invoice.credited', 'invoice', $invoice->id, ['credit_note' => $note->number, 'total' => $note->total_cents / 100, 'refund' => $note->refund, 'reason' => $reason], ['platform' => true]);

        return $note;
    }

    public function pdf(Invoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false); // never fetch anything from the network while rendering
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('billing::invoice', [
            'invoice' => $invoice,
            'lines' => $invoice->lines()->get(),
            'credits' => CreditNote::query()->where('invoice_id', $invoice->id)->orderBy('issued_at')->get(),
            'placeholder' => $this->settings->issuerIsPlaceholder(),
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function creditNotePdf(CreditNote $note, Invoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('billing::credit-note', ['note' => $note, 'invoice' => $invoice])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function nextNumber(string $sequence, string $prefix): string
    {
        $next = (int) DB::table('document_sequences')->where('name', $sequence)->lockForUpdate()->value('next');
        DB::table('document_sequences')->where('name', $sequence)->update(['next' => $next + 1]);

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private static function describe(Payment $payment): string
    {
        $d = $payment->details;

        return match ($payment->purpose) {
            'credits' => 'WURK Credits: '.($d['credits'] ?? '').' credits ('.($d['pack'] ?? 'pack').')',
            'upgrade' => 'Package upgrade to '.($d['package_name'] ?? $d['package'] ?? '').' (prorated for the rest of the period)',
            default => 'WURK subscription: '.ucfirst((string) ($d['package'] ?? '')).', '.(($d['period'] ?? 'monthly') === 'yearly' ? 'one year' : 'one month'),
        };
    }
}
