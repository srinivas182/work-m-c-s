<?php

declare(strict_types=1);

namespace Wurk\Portal\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Referrals\Contracts\CustomerRewards;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * POPIA deletion requests, handled by the business (Sprint 13.6e; decisions G–I). Approving: the wallet must be settled
 * first; the customer is emailed while their address is still known; then their details are erased, portal access
 * ends and their part in the referral programme closes (pending rewards forfeited). Audit entries carry ids and
 * amounts only — never the personal details being deleted.
 */
final readonly class DataRequests
{
    public const DONE = [
        'group' => 'Your information', 'label' => 'Deletion completed', 'placeholders' => ['client', 'business'], 'required' => [],
        'subject' => 'Your information has been deleted — {business}',
        'text' => 'Hi {client}, as you asked, {business} has deleted your information. Records the law requires us to keep (such as invoices) are kept for as long as required.',
        'sample' => ['client' => 'Thandi', 'business' => 'Glow Salon'], 'channels' => ['email'],
    ];

    public const DECLINED = [
        'group' => 'Your information', 'label' => 'Deletion declined', 'placeholders' => ['client', 'business', 'reason'], 'required' => ['reason'],
        'subject' => 'Your request to delete your information — {business}',
        'text' => 'Hi {client}, {business} couldn’t delete your information: {reason}',
        'sample' => ['client' => 'Thandi', 'business' => 'Glow Salon', 'reason' => 'you have a booking next week.'], 'channels' => ['email'],
    ];

    public function __construct(private TenantContext $context, private ContactBook $contacts, private AuditLog $audit) {}

    public function find(string $id): ?DataRequest
    {
        $r = $this->db()->table('data_requests')->where('tenant_id', $this->context->id())->where('id', $id)->first();

        return $r === null ? null : new DataRequest((string) $r->id, (string) $r->contact_id, (string) $r->status);
    }

    /** The customer's wallet, paid outside WURK (decision G). */
    public function settle(DataRequest $request, int $cents, string $method, string $reference): int
    {
        $paid = app(CustomerRewards::class)->payOut($request->contact_id, $cents, $method.($reference !== '' ? ' '.$reference : ''));
        $this->audit->record('portal.deletion_wallet_settled', 'data_request', $request->id, ['cents' => $paid, 'method' => $method]);

        return $paid;
    }

    /** @throws \DomainException while the wallet still holds money (decision G) */
    public function approve(DataRequest $request, string $by): void
    {
        $this->open($request);
        if ((app(CustomerRewards::class)->payouts($request->contact_id)['latest']['status'] ?? null) === 'requested') {
            throw new \DomainException('They have a payout waiting — pay or decline it first (Customers → Payouts).');
        }
        $left = app(StoreCredit::class)->available($request->contact_id);
        if ($left > 0) {
            throw new \DomainException('Pay out or refund their wallet first (R'.number_format($left / 100, 2).').');
        }
        $this->tell($request, 'portal.deletion_done', []);                       // while their address is still known
        app(CustomerRewards::class)->close($request->contact_id);               // pending forfeited (decision I); link stops working
        $this->contacts->erase($request->contact_id);                           // and everything merged into them
        $this->db()->table('portal_customers')->where('tenant_id', $this->context->id())->where('contact_id', $request->contact_id)->delete(); // portal access ends
        $this->decide($request, 'completed', $by, null);
        $this->audit->record('portal.deletion_completed', 'data_request', $request->id, []); // ids only (decision H)
    }

    public function decline(DataRequest $request, string $by, string $reason): void
    {
        $this->open($request);
        $this->tell($request, 'portal.deletion_declined', ['reason' => $reason]);
        $this->decide($request, 'declined', $by, $reason);
        $this->audit->record('portal.deletion_declined', 'data_request', $request->id, []);
    }

    /** @param array<string, string> $extra */
    private function tell(DataRequest $request, string $template, array $extra): void
    {
        $email = $this->contacts->emails([$request->contact_id])[$request->contact_id] ?? null;
        if ($email !== null) {
            app(MessageTemplates::class)->email($template, $email, ['client' => explode(' ', $this->contacts->names([$request->contact_id])[$request->contact_id] ?? 'there')[0],
                'business' => $this->context->current()->displayName(), ...$extra]);
        }
    }

    private function open(DataRequest $request): void
    {
        if ($request->status !== 'open') {
            throw new \DomainException('This request has already been handled.');
        }
    }

    private function decide(DataRequest $request, string $status, string $by, ?string $reason): void
    {
        $this->db()->table('data_requests')->where('tenant_id', $this->context->id())->where('id', $request->id)
            ->update(['status' => $status, 'reason' => $reason, 'decided_by_name' => mb_substr($by, 0, 120), 'decided_at' => now()]);
    }

    private function db(): Connection
    {
        return DB::connection($this->context->connectionName());
    }
}
