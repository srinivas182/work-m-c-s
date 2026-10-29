<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Card and instant-EFT payments into the business's own account (PayFast, Yoco, Ozow, Peach). A branch may
 * have its own account for a method; otherwise the business's is used. Every payment is verified, and
 * refunded, through the same account it was made with.
 */
final readonly class Payments implements BusinessPayments
{
    public function __construct(private TenantContext $context, private AuditLog $audit, private GatewayRouter $router) {}

    /** The account a payment at a branch uses: the branch's own (on, with keys for its mode), else the business's. */
    private function accountFor(string $provider, ?string $branchId): ?PaymentAccount
    {
        if (! app(PlatformMethods::class)->available($provider)) {
            return null; // WURK has switched it off
        }
        $ready = fn (?PaymentAccount $a) => $a !== null && $a->active && $a->hasKeys($a->mode) ? $a : null;
        $own = $branchId === null ? null : $ready(PaymentAccount::query()->where('provider', $provider)->where('branch_id', $branchId)->first());

        return $own ?? $ready($this->business($provider)->first());
    }

    /** @return Builder<PaymentAccount> the business-wide account for a provider */
    private function business(string $provider): Builder
    {
        return PaymentAccount::query()->where('provider', $provider)->whereNull('branch_id');
    }

    /** The exact account an attempt went through. */
    private function accountOf(PaymentAttempt $attempt): ?PaymentAccount
    {
        return PaymentAccount::query()->where('provider', $attempt->provider)
            ->when($attempt->branch_id === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $attempt->branch_id))->first();
    }

    public function available(?string $branchId = null): array
    {
        return array_values(array_filter(PlatformMethods::ONLINE, fn (string $p) => $this->accountFor($p, $branchId) !== null));
    }

    public function start(string $reference, int $amountCents, string $description, array $customer, array $urls, ?string $provider = null, ?string $branchId = null): ?string
    {
        $provider ??= $this->available($branchId)[0] ?? null;
        $account = $provider === null ? null : $this->accountFor($provider, $branchId);
        if ($account === null || $amountCents < 100) {
            return null;
        }
        $attempt = PaymentAttempt::query()->updateOrCreate(['reference' => $reference], ['provider' => $account->provider, 'branch_id' => $account->branch_id, 'amount_cents' => $amountCents, 'status' => 'pending']);
        $handle = $this->context->current()->handle();
        // A branch's own account is told apart in the notification address (its own signature and secrets).
        $urls['notify'] = 'https://'.$handle.'.'.config('wurk.domains.root').'/payments/notify/'.$account->provider.($account->branch_id ? '?branch='.$account->branch_id : '');

        return $this->router->start($account, $attempt, $description, $customer, $urls,
            fn () => URL::signedRoute('payments.public.pay', ['handle' => $handle, 'reference' => $reference], now()->addHour()));
    }

    public function verify(string $reference): array
    {
        $attempt = PaymentAttempt::query()->find($reference);
        $account = $attempt === null ? null : $this->accountOf($attempt);

        return $attempt === null || $account === null ? ['paid' => false, 'amount_cents' => 0] : $this->router->verify($account, $attempt);
    }

    public function refund(string $reference, ?int $amountCents = null): bool
    {
        $attempt = PaymentAttempt::query()->find($reference);
        $account = $attempt === null ? null : $this->accountOf($attempt);
        if ($attempt === null || $account === null || $attempt->status !== 'paid') {
            return false;
        }
        $cents = min($attempt->amount_cents, $amountCents ?? $attempt->amount_cents);
        $ok = $this->router->refund($account, $attempt, $cents);
        $this->audit->record($ok ? 'payments.refunded' : 'payments.refund_failed', 'payment', $reference, ['cents' => $cents, 'provider' => $attempt->provider]);

        return $ok;
    }

    /**
     * A provider's notification for the business's account, or a branch's (the address says which).
     *
     * @param  array<string, string>  $posted
     * @param  array<string, string>  $headers
     */
    public function notified(string $provider, string $body, array $posted, array $headers, ?string $branchId = null): bool
    {
        $account = $branchId === null ? $this->business($provider)->first() : PaymentAccount::query()->where('provider', $provider)->where('branch_id', $branchId)->first();
        $attempt = $account === null ? null : $this->router->notified($account, $body, $posted, $headers,
            fn (string $ref) => PaymentAttempt::query()->find($ref), fn (string $id) => PaymentAttempt::query()->where('provider_id', $id)->first());
        if ($attempt === null) {
            return false;
        }
        event(new BusinessPaymentReceived($this->context->id(), $attempt->reference));

        return true;
    }

    public function eftInstructions(?string $branchId = null): ?string
    {
        $eft = $this->accountFor('eft', $branchId);

        return $eft === null ? null : ((string) ($eft->credentials['instructions'] ?? '') ?: null);
    }

    public function setUpEft(string $instructions): void
    {
        PaymentAccount::query()->updateOrCreate(['provider' => 'eft', 'branch_id' => null], ['mode' => 'live', 'active' => true, 'live_credentials' => ['instructions' => $instructions], 'label' => 'Bank details saved']);
    }
}
