<?php

declare(strict_types=1);

namespace Wurk\Sales\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Contracts\PurchaseRefunded;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Payments\Contracts\RefundWindow;
use Wurk\Sales\Models\Sale;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Record sale (Sprint 13.5): an in-store sale linked to the customer. Above the owner's threshold it waits for
 * approval (unless the person recording can approve). A recorded sale is a purchase — rewards, final once the return
 * window from the sale closes — and the customer gets an email receipt if they have an email. Refunds only within the window.
 */
final readonly class Sales
{
    public const METHODS = ['cash' => 'Cash', 'card' => 'Card', 'eft' => 'EFT'];

    /** The receipt (Settings → Messages): standard wording. */
    public const RECEIPT = [
        'group' => 'Sales', 'label' => 'In-store sale receipt', 'placeholders' => ['client', 'business', 'amount', 'when'], 'required' => [],
        'subject' => 'Your receipt — {business}', 'text' => 'Hi {client}, thank you for your purchase of {amount} at {business} on {when}.',
        'sample' => ['client' => 'Thandi', 'business' => 'Glow Salon', 'amount' => 'R450.00', 'when' => '3 May 2027'], 'channels' => ['email'],
    ];

    public function __construct(private TenantContext $context, private ContactBook $contacts, private AuditLog $audit) {}

    public function threshold(): int
    {
        return (int) (DB::connection($this->context->connectionName())->table('sales_preferences')->where('tenant_id', $this->context->id())->value('approval_above_cents') ?? 0);
    }

    public function setThreshold(int $cents): void
    {
        DB::connection($this->context->connectionName())->table('sales_preferences')->updateOrInsert(['tenant_id' => $this->context->id()], ['approval_above_cents' => $cents]);
        $this->audit->record('sales.threshold', 'sales_preferences', 'approval_above_cents', ['cents' => $cents]);
    }

    /** @param array{name: string, phone: string|null, email: string|null, amount_cents: int, method: string, note: string|null, branch_id: string|null, contact_id?: string|null} $d */
    public function record(array $d, string $byId, string $byName, ?string $device, bool $canApprove): Sale
    {
        $needsApproval = ! $canApprove && $this->threshold() > 0 && $d['amount_cents'] > $this->threshold();
        $sale = DB::connection($this->context->connectionName())->transaction(function () use ($d, $byId, $byName, $device, $needsApproval) {
            $sale = Sale::query()->create(['amount_cents' => $d['amount_cents'], 'method' => $d['method'], 'note' => $d['note'], 'branch_id' => $d['branch_id'],
                'status' => $needsApproval ? 'pending' : 'recorded', 'recorded_by' => $byId, 'recorded_by_name' => $byName, 'device' => $device, 'recorded_at' => now()]);
            $summary = 'In-store sale: R'.number_format($d['amount_cents'] / 100, 2);
            if (($d['contact_id'] ?? null) !== null) { // proven by their code: exactly this customer
                $this->contacts->link($d['contact_id'], 'sale', $sale->id, $d['branch_id'], $summary);
                $contact = $d['contact_id'];
            } else {
                $contact = $this->contacts->remember($d['name'], $d['phone'], $d['email'], 'sale', $sale->id, $d['branch_id'], $summary);
            }
            $sale->update(['contact_id' => $contact]);

            return $sale;
        });
        $this->audit->record('sales.recorded', 'sale', $sale->id, ['cents' => $sale->amount_cents, 'method' => $sale->method, 'status' => $sale->status, 'device' => $device]);
        if ($sale->status === 'recorded') {
            $this->complete($sale);
        }

        return $sale;
    }

    public function approve(Sale $sale, string $byId, string $byName): void
    {
        $this->decide($sale, 'recorded', $byId, $byName);
        $this->complete($sale->refresh());
    }

    public function decline(Sale $sale, string $byId, string $byName): void
    {
        $this->decide($sale, 'declined', $byId, $byName);
    }

    /** A refund in store — only within the return window (decision D), never more than was paid. */
    public function refund(Sale $sale, int $cents, string $by): void
    {
        $window = app(RefundWindow::class);
        if ($sale->status !== 'recorded') {
            throw new SaleProblem('Only recorded sales can be refunded.');
        }
        if (! $window->open($sale->recorded_at)) {
            throw new SaleProblem($window->days() === 0 ? 'This business doesn’t give refunds.' : 'Refunds for this sale closed on '.$window->finalAt($sale->recorded_at)->format('j M Y').'.');
        }
        $cents = min($cents, $sale->amount_cents - $sale->refunded_cents);
        if ($cents <= 0) {
            throw new SaleProblem('This sale has already been refunded in full.');
        }
        $sale->update(['refunded_cents' => $sale->refunded_cents + $cents]);
        event(new PurchaseRefunded('sale', $sale->id, $cents));
        $this->audit->record('sales.refunded', 'sale', $sale->id, ['cents' => $cents, 'by' => $by]);
    }

    private function decide(Sale $sale, string $status, string $byId, string $byName): void
    {
        if ($sale->status !== 'pending') {
            throw new SaleProblem('This sale has already been decided.');
        }
        $sale->update(['status' => $status, 'decided_by' => $byId, 'decided_by_name' => $byName, 'decided_at' => now()]);
        $this->audit->record($status === 'recorded' ? 'sales.approved' : 'sales.declined', 'sale', $sale->id, ['by' => $byName]);
    }

    /** A recorded sale: a purchase (rewards; final once the return window closes) and an email receipt. */
    private function complete(Sale $sale): void
    {
        event(new PurchaseMade('sale', $sale->id, $sale->amount_cents, $sale->amount_cents, $sale->branch_id, app(RefundWindow::class)->finalAt($sale->recorded_at)));
        $email = $sale->contact_id === null ? null : ($this->contacts->emails([$sale->contact_id])[$sale->contact_id] ?? null);
        if ($email === null || $sale->contact_id === null) {
            return; // no email: no receipt (an SMS would spend the business's credits on every sale)
        }
        $tenant = $this->context->current();
        app(MessageTemplates::class)->email('sale.receipt', $email, ['client' => $this->contacts->names([$sale->contact_id])[$sale->contact_id] ?? 'there',
            'business' => $tenant->displayName(), 'amount' => 'R'.number_format($sale->amount_cents / 100, 2), 'when' => $sale->recorded_at->setTimezone($tenant->timezone())->format('j M Y')]);
    }
}
