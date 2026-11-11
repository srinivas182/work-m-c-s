<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonInterface;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHold;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\CouponCustomer;
use Wurk\Payments\Contracts\Coupons;
use Wurk\Payments\Contracts\RefundWindow;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Team\Contracts\BranchLedger;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Booking deposits, paid straight to the business's Paystack subaccount. A booking awaiting its
 * deposit holds the time for HOLD_MINUTES; payment is confirmed only after verifying with Paystack,
 * and confirming is idempotent (the redirect and the webhook may both arrive).
 */
final readonly class Deposits
{
    public const HOLD_MINUTES = 15;

    public function __construct(private TenantContext $context, private BusinessPayments $payments, private Booker $booker, private AuditLog $audit) {}

    /**
     * What to pay before the booking is confirmed, in cents: none, a fixed or percentage deposit, or
     * the full price — always, or only for online appointments. 0 when payouts aren't set up.
     */
    public function amountFor(BookingService $service, bool $online = false, ?string $branchId = null): int
    {
        if ($service->deposit_when === 'online' && ! $online) {
            return 0; // paid at the desk in person
        }
        if ($this->payments->available($branchId) === []) { // no card payments for this branch (Settings → Payments)
            return 0;
        }

        $price = app(BranchServices::class)->priceAt($service, $branchId); // the price at this branch (an override, or the service's own)

        return match ($service->deposit_type) {
            'fixed' => $service->deposit_value,
            'percent' => (int) round($price * min(100, $service->deposit_value) / 100),
            'full' => $price,
            default => 0,
        };
    }

    /** What to pay for a place in a class (0 = paid at the class, or payouts not set up). */
    public function amountForClass(ClassSession $session): int
    {
        if (! $session->pay_online || $this->payments->available($session->branch_id) === []) {
            return 0;
        }

        return $session->price_cents;
    }

    /**
     * Start paying: the booking (pending_payment) holds its time; returns the provider's checkout page — or, when the
     * customer's wallet pays the whole deposit (Sprint 13.6c), the confirmation page straight away.
     *
     * @param  array{pass: string, cents: int}|null  $wallet
     *
     * @throws \DomainException the wallet couldn't be used (a message for the customer)
     */
    public function start(Booking|ClassAttendee $booking, int $amountCents, string $email, string $returnUrl, ?array $wallet = null, ?string $coupon = null): ?string
    {
        $reference = 'dep_'.$this->context->id().'_'.$booking->id; // routes Paystack's webhook to the business
        $expires = now()->addMinutes(self::HOLD_MINUTES);
        // A coupon (15.3b) comes off the deposit; the stored deposit is what's due, so payment checks match.
        $coupons = app(Coupons::class);
        $key = CouponCustomer::key($booking->customer_phone);
        $quote = $coupon === null || trim($coupon) === '' ? null : $coupons->quote($coupon, $amountCents, 'bookings', $key);
        if ($quote !== null && ! $quote['ok']) {
            throw new \DomainException((string) $quote['message']);
        }
        $amountCents -= $quote['discount_cents'] ?? 0;
        $booking->update(['deposit_cents' => $amountCents, 'payment_reference' => $reference, 'hold_expires_at' => Instant::db($expires)]);
        if ($quote !== null) {
            $coupons->redeem((string) $quote['coupon_id'], self::source($booking), $booking->id, (int) $quote['discount_cents'], $key);
            if ($amountCents <= 0) { // the coupon covers it all
                $this->settle($booking->refresh(), $reference);

                return $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.rawurlencode($reference);
            }
        }
        try {
            return $this->charge($booking, $amountCents, $email, $returnUrl, $wallet, $reference, $expires);
        } catch (\Throwable $e) {
            $coupons->release(self::source($booking), $booking->id); // the booking won't stand: the use goes back

            throw $e;
        }
    }

    /** @param array{pass: string, cents: int}|null $wallet */
    private function charge(Booking|ClassAttendee $booking, int $amountCents, string $email, string $returnUrl, ?array $wallet, string $reference, CarbonInterface $expires): ?string
    {
        if ($wallet !== null && $wallet['cents'] > 0) {
            $credit = app(StoreCredit::class);
            $contact = $credit->contactFromPass($wallet['pass']) ?? throw new \DomainException('Your wallet couldn’t be used — sign in to your account again and retry.');
            // Up to what the business's share rule allows for this deposit (the booking form only knows the price).
            $cents = min($wallet['cents'], $credit->usableFor($contact, $amountCents));
            if ($cents > 0) {
                $credit->spend($contact, $cents, self::source($booking), $booking->id, $amountCents, $booking instanceof ClassAttendee ? $booking->session()->branch_id : $booking->branch_id);
                $booking->update(['wallet_cents' => $cents]);
                if ($cents >= $amountCents) { // the wallet paid all of it
                    $this->settle($booking->refresh(), $reference);

                    return $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.rawurlencode($reference);
                }
                $amountCents -= $cents; // the card pays the rest
            }
        }
        BookingHold::query()->updateOrCreate(['reference' => $reference], ['tenant_id' => $this->context->id(), 'expires_at' => Instant::db($expires)]);
        $name = $booking->customer_name;
        $what = $booking instanceof ClassAttendee ? 'Class: '.$booking->session()->title : 'Booking deposit';

        // The return address carries the reference (Yoco and PayFast don't add it).
        $branch = $booking instanceof ClassAttendee ? $booking->session()->branch_id : $booking->branch_id; // the branch's own account, if it has one

        return $this->payments->start($reference, $amountCents, $what, ['name' => $name, 'email' => $email],
            ['return' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.rawurlencode($reference), 'cancel' => (string) preg_replace('#/book/return.*$#', '/', $returnUrl)], branchId: $branch); // cancelling goes back to the home page
    }

    /**
     * Confirm a paid deposit by its reference (from the redirect or the webhook). Verified with Paystack;
     * safe to call twice. A payment that arrives after its hold expired is refunded.
     */
    public function confirm(string $reference): Booking|ClassAttendee|null
    {
        if (preg_match('/^dep_([0-9A-Za-z]{26})_([0-9A-Za-z]{26})$/', $reference, $m) !== 1) {
            return null;
        }
        $tenant = app(TenantDirectory::class)->find($m[1]);
        if ($tenant === null) {
            return null;
        }

        return $this->context->run($tenant, function () use ($reference): Booking|ClassAttendee|null {
            $booking = Booking::query()->where('payment_reference', $reference)->first()
                ?? ClassAttendee::query()->where('payment_reference', $reference)->first();
            if ($booking === null || $booking->paid_at !== null) {
                return $booking; // unknown, or already confirmed
            }
            $result = $this->payments->verify($reference);
            if (! $result['paid'] || $result['amount_cents'] < $booking->deposit_cents - (int) $booking->wallet_cents) { // what's due after the wallet
                return $booking;
            }
            if ($booking->status !== 'pending_payment') { // the hold expired before payment arrived
                $refunded = $this->payments->refund($reference);
                $booking->update(['paid_at' => Instant::db(now()), 'refunded_at' => $refunded ? Instant::db(now()) : null]);
                $this->audit->record('bookings.deposit_refunded_after_expiry', 'booking', $booking->id, ['refunded' => $refunded]);

                return $booking->refresh();
            }
            $this->settle($booking, $reference);

            return $booking->refresh();
        });
    }

    /** A paid deposit: confirmed, the cash recorded, rewards on the full deposit (decision E). */
    private function settle(Booking|ClassAttendee $booking, string $reference): void
    {

        $booking->update(['status' => 'confirmed', 'paid_at' => Instant::db(now()), 'hold_expires_at' => null]);
        BookingHold::query()->whereKey($reference)->delete();
        $supplier = $booking instanceof ClassAttendee ? $booking->session()->branch_id : $booking->branch_id;
        app(BranchLedger::class)->record($this->payments->receivedBy($reference), $supplier, $booking->deposit_cents - (int) $booking->wallet_cents,
            $booking instanceof ClassAttendee ? 'class' : 'deposit', $reference, $booking instanceof ClassAttendee ? 'Class place: '.$booking->session()->title : 'Booking deposit: '.$booking->customer_name);
        $this->audit->record('bookings.deposit_paid', $booking instanceof ClassAttendee ? 'class_attendee' : 'booking', $booking->id, ['cents' => $booking->deposit_cents]);
        event($booking instanceof ClassAttendee ? new ClassJoined($this->context->id(), $booking->id) : new BookingConfirmed($this->context->id(), $booking->id));
        event(new PurchaseMade($booking instanceof ClassAttendee ? 'class' : 'booking', $booking->id, $booking->deposit_cents, $booking->deposit_cents, $booking instanceof ClassAttendee ? $booking->session()->branch_id : $booking->branch_id,
            app(RefundWindow::class)->finalAt($booking instanceof ClassAttendee ? $booking->session()->starts_at : $booking->starts_at))); // final: the appointment + the window

    }

    public static function source(Booking|ClassAttendee $booking): string
    {
        return $booking instanceof ClassAttendee ? 'class' : 'booking';
    }

    /** Release the times of bookings whose deposit wasn't paid in time (run every minute, across businesses). */
    public function expireHolds(): int
    {
        $count = 0;
        $tenants = app(TenantDirectory::class);
        foreach (BookingHold::query()->where('expires_at', '<', Instant::db(now()))->get()->groupBy('tenant_id') as $tenantId => $holds) {
            $tenant = $tenants->find((string) $tenantId);
            if ($tenant !== null) {
                $count += (int) $this->context->run($tenant, function () use ($holds): int {
                    $n = 0;
                    foreach (Booking::query()->whereIn('payment_reference', $holds->pluck('reference'))->where('status', 'pending_payment')->get() as $booking) {
                        $this->booker->cancel($booking, null, 'expired');
                        app(StoreCredit::class)->giveBack('booking', $booking->id, (int) $booking->wallet_cents); // never paid: the wallet part goes back
                        $n++;
                    }
                    foreach (ClassAttendee::query()->whereIn('payment_reference', $holds->pluck('reference'))->where('status', 'pending_payment')->pluck('id') as $placeId) {
                        app(Coupons::class)->release('class', (string) $placeId); // never paid: the coupon use goes back
                    }
                    foreach (ClassAttendee::query()->whereIn('payment_reference', $holds->pluck('reference'))->where('status', 'pending_payment')->where('wallet_cents', '>', 0)->get() as $place) {
                        app(StoreCredit::class)->giveBack('class', $place->id, (int) $place->wallet_cents);
                    }
                    // Class places held while paying: released (no message — nothing was confirmed).
                    $n += ClassAttendee::query()->whereIn('payment_reference', $holds->pluck('reference'))->where('status', 'pending_payment')->update(['status' => 'expired']);

                    return $n;
                });
            }
            BookingHold::query()->whereIn('reference', $holds->pluck('reference'))->delete();
        }

        return $count;
    }
}
