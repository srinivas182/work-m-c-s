<?php

declare(strict_types=1);

namespace Wurk\Money\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Money\Mail\InvoiceMail;
use Wurk\Money\Models\Invoice;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Payments\Contracts\Refunds;
use Wurk\Platform\Money\Money;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Simple invoices (Sprint 23.5a, decision C): one-off, with lines, VAT when the business is VAT-registered, a due date
 * and a pay link (the business's own payment methods, Sprint 22). Sent from the business's WhatsApp number (an
 * approved template), else SMS, else email. Paid by link: marked paid once the provider confirms. Paid in person:
 * recorded with who took it, and counted in the day's takings.
 *
 * Reminders (decision D): automatic on days 1, 7 and 14 after the due date — never more than one a week, three in all —
 * WhatsApp first, then SMS, then email. A customer's STOP is respected, and the owner can switch them off per invoice.
 */
final readonly class Invoices
{
    public const MAX_LINES = 30;

    /** Days after the due date that a reminder goes out. */
    public const REMINDER_DAYS = [1, 7, 14];

    public function __construct(private TenantContext $context, private BusinessPayments $payments, private InPersonPayments $inPerson, private WhatsAppBusiness $whatsapp,
        private Messenger $messenger, private CreditWallet $wallet, private AuditLog $audit, private Countries $countries) {}

    /**
     * @param  array{customer_name: string, customer_phone?: string|null, customer_email?: string|null, lines: list<array{description: string, quantity: int|float|string, unit: int|float|string}>,
     *   due_on: string, vat?: bool, vat_number?: string|null}  $data  unit: rand
     */
    public function create(array $data, ?string $by): Invoice
    {
        $lines = [];
        foreach (array_slice($data['lines'], 0, self::MAX_LINES) as $l) {
            $description = trim(mb_substr((string) $l['description'], 0, 200));
            $quantity = max(1, (int) $l['quantity']);
            $unit = (int) round((float) $l['unit'] * 100);
            if ($description !== '' && $unit > 0) {
                $lines[] = ['description' => $description, 'quantity' => $quantity, 'unit_cents' => $unit];
            }
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line with a price.']);
        }
        $now = CarbonImmutable::now($this->context->current()->timezone());
        $today = $now->toDateString();
        // The business's country's tax on the invoice's day (25.1), levies included; the invoice keeps it from then on.
        $vatBp = ($data['vat'] ?? false) ? (int) ($this->countries->taxOn($this->context->current()->countryCode(), $now)['total_bp'] ?? 0) : 0;
        $subtotal = array_sum(array_map(fn (array $l) => $l['quantity'] * $l['unit_cents'], $lines));
        $vat = (int) round($subtotal * $vatBp / 10000);

        return DB::connection($this->context->connectionName())->transaction(function () use ($data, $lines, $vatBp, $subtotal, $vat, $today, $by): Invoice {
            $number = (int) Invoice::query()->lockForUpdate()->max('number') + 1;
            $invoice = Invoice::query()->create([
                'number' => $number, 'customer_name' => trim(mb_substr($data['customer_name'], 0, 120)),
                'customer_phone' => self::phone($data['customer_phone'] ?? null), 'customer_email' => ($data['customer_email'] ?? '') !== '' ? strtolower(trim((string) $data['customer_email'])) : null,
                'lines' => $lines, 'vat_bp' => $vatBp, 'vat_number' => $vatBp > 0 ? (trim((string) ($data['vat_number'] ?? '')) ?: null) : null,
                'subtotal_cents' => $subtotal, 'vat_cents' => $vat, 'total_cents' => $subtotal + $vat,
                'issued_on' => $today, 'due_on' => max($today, $data['due_on']), 'status' => 'draft', 'created_by' => $by,
            ]);
            $invoice->update(['payment_reference' => 'inv_'.$this->context->id().'_'.$invoice->id]); // routes the provider's webhook to the business
            $this->audit->record('money.invoice_created', 'invoice', $invoice->id, ['number' => $number, 'total_cents' => $subtotal + $vat]);

            return $invoice;
        });
    }

    /** The customer's page for this invoice, with the pay button (signed: only whoever has the link can open it). */
    public function link(Invoice $invoice): string
    {
        return URL::signedRoute('money.public.invoice', ['handle' => $this->context->current()->handle(), 'invoice' => $invoice->id]);
    }

    /**
     * Send it to the customer: from the business's WhatsApp number when its template is approved, else SMS, else email.
     *
     * @return string how it went ('whatsapp', 'sms', 'email'), or 'link' when there's no way to send it (share the link)
     */
    public function send(Invoice $invoice, ?string $by): string
    {
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is '.($invoice->status === 'paid' ? 'paid' : 'cancelled').'.']);
        }
        $how = $this->deliver($invoice, false);
        $invoice->update(['status' => 'sent', 'sent_at' => $invoice->sent_at ?? now(), 'sent_by' => $how]);
        $this->audit->record('money.invoice_sent', 'invoice', $invoice->id, ['how' => $how, 'by' => $by]);

        return $how;
    }

    /** A reminder now (one tap, or the daily run); false when it isn't due a reminder or can't be reached. */
    public function remind(Invoice $invoice, bool $automatic): bool
    {
        if ($invoice->status !== 'sent' || ! $invoice->reminders_on || $invoice->reminders_sent >= count(self::REMINDER_DAYS)
            || ($invoice->last_reminded_at !== null && $invoice->last_reminded_at->gt(now()->subDays(6))) // never more than one a week
            || ($invoice->customer_phone !== null && $this->stopped($invoice->customer_phone) && $invoice->customer_email === null)) {
            return false;
        }
        $how = $this->deliver($invoice, true);
        if ($how === 'link') {
            return false;
        }
        $invoice->update(['reminders_sent' => $invoice->reminders_sent + 1, 'last_reminded_at' => now()]);
        $this->audit->record('money.invoice_reminded', 'invoice', $invoice->id, ['how' => $how, 'automatic' => $automatic, 'count' => $invoice->reminders_sent]);

        return true;
    }

    /** Daily: reminders for the business's overdue invoices on days 1, 7 and 14 after their due date. */
    public function remindOverdue(): int
    {
        $today = CarbonImmutable::now($this->context->current()->timezone())->startOfDay();
        $n = 0;
        foreach (Invoice::query()->where('status', 'sent')->where('reminders_on', true)->where('due_on', '<', $today->toDateString())->where('reminders_sent', '<', count(self::REMINDER_DAYS))->get() as $invoice) {
            $late = (int) CarbonImmutable::parse($invoice->due_on->toDateString(), $this->context->current()->timezone())->diffInDays($today);
            if ($late >= self::REMINDER_DAYS[$invoice->reminders_sent] && $this->remind($invoice, true)) {
                $n++;
            }
        }

        return $n;
    }

    /** Paid in person (cash, card machine, QR at the counter) or by EFT the owner has seen arrive. */
    public function markPaid(Invoice $invoice, string $how, ?string $by, string $byName): void
    {
        if ($invoice->status === 'paid' || $invoice->status === 'void') {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already '.($invoice->status === 'paid' ? 'paid' : 'cancelled').'.']);
        }
        if (! isset(InPersonPayments::METHODS[$how]) && $how !== 'eft') {
            throw ValidationException::withMessages(['how' => 'Choose how it was paid.']);
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($invoice, $how, $by, $byName): void {
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'paid_how' => $how]);
            if (isset(InPersonPayments::METHODS[$how])) {
                $this->inPerson->record('invoice', $invoice->id, $how, $invoice->total_cents, $by, $byName, null);
            }
        });
        $this->audit->record('money.invoice_paid', 'invoice', $invoice->id, ['how' => $how]);
    }

    /** How a paid invoice can be paid back: online payments only through the provider; others by hand (25.5). */
    public const REFUND_BY_HAND = ['cash', 'card_machine', 'qr', 'eft', 'mobile_money'];

    /** Refund all or part of a paid invoice, with a reason; the customer is told and cash-up counts a counter refund. */
    public function refund(Invoice $invoice, int $cents, string $method, string $reason, ?string $note, ?string $byId, string $byName): void
    {
        if ($invoice->status !== 'paid') {
            throw ValidationException::withMessages(['amount' => __('Only a paid invoice can be refunded.')]);
        }
        $left = $invoice->total_cents - (int) $invoice->refunded_cents;
        if ($cents < 1 || $cents > $left) {
            throw ValidationException::withMessages(['amount' => __('Refund up to :amount (what’s left of this invoice).', ['amount' => Money::format($left)])]);
        }
        $online = $invoice->paid_how === 'online';
        if ($online && $method !== 'provider' && $method !== 'eft') { // eft: refunded in the provider's dashboard, recorded here
            throw ValidationException::withMessages(['method' => __('This invoice was paid online: the refund goes back the same way.')]);
        }
        if (! $online && ! in_array($method, self::REFUND_BY_HAND, true)) {
            throw ValidationException::withMessages(['method' => __('Choose how you paid it back.')]);
        }
        if ($online && $method === 'provider' && ! $this->payments->refund((string) $invoice->payment_reference, $cents)) {
            throw ValidationException::withMessages(['method' => __('The payment provider didn’t accept the refund. Refund it in their dashboard, then record it here as an EFT refund.')]);
        }
        $invoice->update(['refunded_cents' => (int) $invoice->refunded_cents + $cents]);
        app(Refunds::class)->record('invoice', $invoice->id, $cents, $method, $reason, $note, $byId, $byName, null,
            ['name' => $invoice->customer_name, 'phone' => $invoice->customer_phone, 'email' => $invoice->customer_email, 'what' => $invoice->label()]);
    }

    /** The VAT inside a refund: the same share of the invoice's VAT as the refund is of its total (VAT reverses with it). */
    public static function refundVat(Invoice $invoice, int $refundedCents): int
    {
        return $invoice->total_cents < 1 ? 0 : intdiv($invoice->vat_cents * $refundedCents * 2 + $invoice->total_cents, $invoice->total_cents * 2);
    }

    /** The customer is paying online: where to send them (null when the business takes no online payments). */
    public function pay(Invoice $invoice, string $returnUrl): ?string
    {
        if ($invoice->status !== 'sent' && $invoice->status !== 'draft') {
            return null;
        }

        return $this->payments->start((string) $invoice->payment_reference, $invoice->total_cents, $this->context->current()->displayName().' '.$invoice->label(),
            ['name' => $invoice->customer_name, 'email' => $invoice->customer_email, 'phone' => $invoice->customer_phone],
            ['return' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.rawurlencode((string) $invoice->payment_reference), 'cancel' => $returnUrl]);
    }

    /** A provider says it's paid (or the customer came back): checked with the provider before it counts. */
    public function confirm(string $reference): ?Invoice
    {
        $invoice = Invoice::query()->where('payment_reference', $reference)->first();
        if ($invoice === null || $invoice->status === 'paid' || $invoice->status === 'void') {
            return $invoice;
        }
        $result = $this->payments->verify($reference);
        if ($result['paid'] && $result['amount_cents'] >= $invoice->total_cents) {
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'paid_how' => 'online']);
            $this->audit->record('money.invoice_paid', 'invoice', $invoice->id, ['how' => 'online']);
        }

        return $invoice->refresh();
    }

    public function void(Invoice $invoice): void
    {
        if ($invoice->status === 'paid') {
            throw ValidationException::withMessages(['invoice' => 'A paid invoice can’t be cancelled.']);
        }
        $invoice->update(['status' => 'void']);
        $this->audit->record('money.invoice_voided', 'invoice', $invoice->id, []);
    }

    /** A customer replied STOP: no more payment reminders to that number from this business. */
    public function stop(string $phone): void
    {
        $phone = self::phone($phone);
        if ($phone !== null) {
            DB::connection($this->context->connectionName())->table('money_reminder_stops')->insertOrIgnore(['tenant_id' => $this->context->id(), 'phone' => $phone, 'stopped_at' => now()]);
        }
    }

    public function stopped(string $phone): bool
    {
        return DB::connection($this->context->connectionName())->table('money_reminder_stops')->where('tenant_id', $this->context->id())->where('phone', self::phone($phone))->exists();
    }

    /** The message's words: the same for WhatsApp's template values, SMS and email. */
    public function words(Invoice $invoice, bool $reminder): string
    {
        $locale = $this->customerLocale($invoice); // the customer's language (24.2)
        $what = $invoice->label().($reminder ? ', '.__('due :date', ['date' => $invoice->due_on->format('j M')], $locale) : '');

        return __('Hi :name, :business has sent you a request to pay :amount for :what. You can view and pay it here: :link Thank you.', [
            'name' => self::first($invoice->customer_name), 'business' => $this->context->current()->displayName(), 'amount' => self::rand($invoice->total_cents),
            'what' => $what, 'link' => $this->link($invoice),
        ], $locale).($reminder ? ' '.__('Reply STOP to stop these reminders.', [], $locale) : '');
    }

    private function customerLocale(Invoice $invoice): ?string
    {
        try {
            return app(ContactBook::class)->locale($invoice->customer_phone, $invoice->customer_email);
        } catch (Throwable) {
            return null;
        }
    }

    private function deliver(Invoice $invoice, bool $reminder): string
    {
        $link = $this->link($invoice);
        $phone = $invoice->customer_phone;
        $stopped = $reminder && $phone !== null && $this->stopped($phone);
        if ($phone !== null && ! $stopped) {
            try {
                if ($this->whatsapp->templateReady(WhatsAppBusiness::PAYMENT_REQUEST)
                    && $this->whatsapp->sendTemplate(ltrim($phone, '+'), WhatsAppBusiness::PAYMENT_REQUEST, [self::first($invoice->customer_name), $this->context->current()->displayName(),
                        self::rand($invoice->total_cents), $invoice->label().($reminder ? ', due '.$invoice->due_on->format('j M') : ''), $link])->ok) {
                    return 'whatsapp';
                }
            } catch (Throwable) {
            }
            if ($this->messenger->channelActive('sms')) {
                $charge = 'invoice:'.$invoice->id.':'.($reminder ? 'r'.($invoice->reminders_sent + 1) : 'send');
                try {
                    $this->wallet->charge('sms', $charge, 'Invoice '.($reminder ? 'reminder' : 'message').' (SMS)');
                    if ($this->messenger->sms($phone, $this->words($invoice, $reminder), 'invoice')->ok) {
                        return 'sms';
                    }
                    $this->wallet->refund($charge, 'Message not delivered');
                } catch (InsufficientCredits) {
                }
            }
        }
        if ($invoice->customer_email !== null) {
            Mail::to($invoice->customer_email)->send(new InvoiceMail($this->context->current()->displayName(), $invoice->label(), self::rand($invoice->total_cents),
                $invoice->due_on->format('j F Y'), $link, $reminder));

            return 'email';
        }

        return 'link';
    }

    /** South African numbers to +27…; anything else as typed (digits and a leading +). */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+27'.substr($digits, 1);
        }

        return str_starts_with($digits, '+') ? $digits : '+'.$digits;
    }

    public static function rand(int $cents): string
    {
        return Money::format((int) $cents);
    }

    private static function first(string $name): string
    {
        return explode(' ', trim($name))[0] ?: $name;
    }
}
