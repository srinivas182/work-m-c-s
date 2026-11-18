<?php

declare(strict_types=1);

namespace Wurk\Money\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Money\Models\Invoice;
use Wurk\Money\Support\Invoices;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Money → Who owes me (23.5a). One list of what customers owe, and simple invoices with pay links and reminders. */
final class OwedController
{
    public function __construct(private Invoices $invoices, private Countries $countries, private TenantContext $context) {}

    public function index(MoneyOwed $owed): Response
    {
        $items = $owed->all();

        return Inertia::render('Money::Owed', [
            'owed' => $items,
            'total_cents' => array_sum(array_column($items, 'amount_cents')),
            'invoices' => Invoice::query()->orderByDesc('number')->limit(50)->get()->map(fn (Invoice $i) => [
                'id' => $i->id, 'label' => $i->label(), 'customer' => $i->customer_name, 'total_cents' => $i->total_cents, 'status' => $i->status,
                'due_on' => $i->due_on->toDateString(), 'overdue' => $i->status === 'sent' && $i->due_on->isPast() && ! $i->due_on->isToday(),
                'sent_by' => $i->sent_by, 'reminders_sent' => $i->reminders_sent, 'reminders_on' => $i->reminders_on, 'paid_how' => $i->paid_how,
                'link' => $this->invoices->link($i), 'whatsapp' => 'https://wa.me/'.ltrim((string) $i->customer_phone, '+').'?text='.rawurlencode($this->invoices->words($i, false)),
                'has_phone' => $i->customer_phone !== null,
            ])->all(),
            'paidMethods' => [...InPersonPayments::METHODS, 'eft' => 'EFT (it’s in the bank)'],
            'today' => now()->toDateString(),
            'tax' => $this->tax(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'], 'customer_phone' => ['nullable', 'string', 'max:30'], 'customer_email' => ['nullable', 'email:rfc', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:'.Invoices::MAX_LINES], 'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'], 'lines.*.unit' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'due_on' => ['required', 'date_format:Y-m-d'], 'vat' => ['boolean'], 'vat_number' => ['nullable', 'string', 'max:30'], 'send' => ['boolean'],
        ], ['lines.*.unit.min' => 'Each line needs a price.']);
        $by = (string) $request->user()?->getAuthIdentifier();
        $invoice = $this->invoices->create($data, $by);
        if ($data['send'] ?? false) {
            return back()->with('status', $invoice->label().' '.self::sent($this->invoices->send($invoice, $by)));
        }

        return back()->with('status', $invoice->label().' saved. Send it when you’re ready.');
    }

    public function send(Request $request, string $invoice): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        return back()->with('status', $model->label().' '.self::sent($this->invoices->send($model, (string) $request->user()?->getAuthIdentifier())));
    }

    public function remind(string $invoice): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        return $this->invoices->remind($model, false)
            ? back()->with('status', 'Reminder sent for '.$model->label().'.')
            : back()->with('warning', 'No reminder sent: '.$model->label().' had one this week, has had three, or the customer asked for no more.');
    }

    public function paid(Request $request, TeamDirectory $team, string $invoice): RedirectResponse
    {
        $how = (string) $request->validate(['how' => ['required', 'string', 'max:20']])['how'];
        $me = (string) $request->user()?->getAuthIdentifier();
        $name = (string) (collect($team->roster())->firstWhere('identity_id', $me)['name'] ?? 'Team');
        $model = Invoice::query()->findOrFail($invoice);
        $this->invoices->markPaid($model, $how, $me, $name);

        return back()->with('status', $model->label().' marked paid.');
    }

    public function reminders(Request $request, string $invoice): RedirectResponse
    {
        $on = $request->boolean('on');
        Invoice::query()->findOrFail($invoice)->update(['reminders_on' => $on]);

        return back()->with('status', $on ? 'Reminders on.' : 'No more reminders for this invoice.');
    }

    public function void(string $invoice): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);
        $this->invoices->void($model);

        return back()->with('status', $model->label().' cancelled.');
    }

    private static function sent(string $how): string
    {
        return match ($how) {
            'whatsapp' => 'sent on WhatsApp from your number.',
            'sms' => 'sent by SMS.',
            'email' => 'sent by email.',
            default => 'is ready: there’s no number or email to send it to, so share its link.',
        };
    }

    /** @return array{name: string, rate: float} */
    private function tax(): array
    {
        $business = $this->context->current();
        $tax = $this->countries->taxOn($business->countryCode(), CarbonImmutable::now($business->timezone()));

        return ['name' => $tax['name'] ?? 'VAT', 'rate' => ($tax['total_bp'] ?? 0) / 100];
    }
}
