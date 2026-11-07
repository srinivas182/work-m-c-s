<?php

declare(strict_types=1);

namespace Wurk\Referrals\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralPayout;
use Wurk\Referrals\Support\Payouts;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Payouts: customers' payout requests; the business pays from its own bank and marks them paid (Sprint 13.7). */
final class PayoutsController
{
    public const PAID = [
        'group' => 'Rewards', 'label' => 'Payout paid', 'placeholders' => ['client', 'business', 'amount', 'reference'], 'required' => ['amount'],
        'subject' => 'Your payout from {business}', 'text' => 'Hi {client}, {business} has paid {amount} into your account (reference: {reference}).',
        'sample' => ['client' => 'Thandi', 'business' => 'Glow Salon', 'amount' => 'R150.00', 'reference' => 'GLOW-0042'], 'channels' => ['email'],
    ];

    public const DECLINED = [
        'group' => 'Rewards', 'label' => 'Payout declined', 'placeholders' => ['client', 'business', 'amount', 'reason'], 'required' => ['reason'],
        'subject' => 'Your payout request — {business}', 'text' => 'Hi {client}, {business} couldn’t pay out {amount}: {reason} It’s back in your wallet.',
        'sample' => ['client' => 'Thandi', 'business' => 'Glow Salon', 'amount' => 'R150.00', 'reason' => 'the account number didn’t match the name.'], 'channels' => ['email'],
    ];

    public function __construct(private TenantContext $context, private ContactBook $contacts, private Payouts $payouts) {}

    public function index(): Response
    {
        $rows = ReferralPayout::query()->orderByRaw("case when status = 'requested' then 0 else 1 end")->orderBy('created_at')->limit(200)->get();
        $members = ReferralMember::query()->whereIn('id', $rows->pluck('member_id'))->pluck('contact_id', 'id');
        $names = $this->contacts->names(array_values(array_unique(array_map('strval', $members->all()))));
        $tz = $this->context->current()->timezone();

        return Inertia::render('Referrals::Payouts', ['payouts' => $rows->map(fn (ReferralPayout $p) => [
            'id' => $p->id, 'customer' => $names[$members[$p->member_id] ?? ''] ?? 'Customer', 'cents' => $p->cents, 'status' => $p->status,
            'asked' => $p->created_at->setTimezone($tz)->format('j M Y'), 'holder' => $p->account_holder, 'bank' => $p->bank, 'branch' => $p->branch_code,
            // The full number only while it's needed to pay; afterwards only the last four digits remain.
            'account' => $p->status === 'requested' ? $p->account_number : '•••• '.$p->account_last4,
            'reference' => $p->reference, 'reason' => $p->reason,
            'decided' => $p->decided_at === null ? null : $p->decided_at->setTimezone($tz)->format('j M Y').' by '.$p->decided_by_name,
        ])->all()]);
    }

    public function paid(Request $request, string $payout): RedirectResponse
    {
        $reference = (string) $request->validate(['reference' => ['required', 'string', 'max:80']], ['reference.required' => 'Add your payment reference.'])['reference'];
        $p = $this->find($payout);
        $this->act(fn () => $this->payouts->markPaid($p, $reference, $this->who($request)));
        $this->tell($p, 'referrals.payout_paid', ['reference' => $reference]);

        return back()->with('status', 'Marked paid — the customer has been told.');
    }

    public function decline(Request $request, string $payout): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the customer why.'])['reason'];
        $p = $this->find($payout);
        $this->act(fn () => $this->payouts->decline($p, $reason, $this->who($request)));
        $this->tell($p, 'referrals.payout_declined', ['reason' => $reason]);

        return back()->with('status', 'Declined — the amount is back in their wallet and they’ve been told why.');
    }

    private function act(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['payout' => $e->getMessage()]);
        }
    }

    /** @param array<string, string> $extra */
    private function tell(ReferralPayout $p, string $template, array $extra): void
    {
        $contact = (string) ReferralMember::query()->whereKey($p->member_id)->value('contact_id');
        $email = $this->contacts->emails([$contact])[$contact] ?? null;
        if ($email !== null) {
            app(MessageTemplates::class)->email($template, $email, ['client' => explode(' ', $this->contacts->names([$contact])[$contact] ?? 'there')[0],
                'business' => $this->context->current()->displayName(), 'amount' => 'R'.number_format($p->cents / 100, 2), ...$extra]);
        }
    }

    private function find(string $id): ReferralPayout
    {
        return ReferralPayout::query()->whereKey($id)->firstOrFail();
    }

    private function who(Request $request): string
    {
        return trim((string) data_get($request->user(), 'first_name').' '.(string) data_get($request->user(), 'last_name')) ?: 'Team';
    }
}
