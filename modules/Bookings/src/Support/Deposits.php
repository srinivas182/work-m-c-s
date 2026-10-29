<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHold;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Payments\Contracts\BusinessPayments;
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
        if ($this->payments->available() === []) { // no card payments connected (Settings → Payments)
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
        if (! $session->pay_online || $this->payments->available() === []) {
            return 0;
        }

        return $session->price_cents;
    }

    /** Start paying: the booking (pending_payment) holds its time; returns Paystack's checkout page. */
    public function start(Booking|ClassAttendee $booking, int $amountCents, string $email, string $returnUrl): ?string
    {
        $reference = 'dep_'.$this->context->id().'_'.$booking->id; // routes Paystack's webhook to the business
        $expires = now()->addMinutes(self::HOLD_MINUTES);
        $booking->update(['deposit_cents' => $amountCents, 'payment_reference' => $reference, 'hold_expires_at' => Instant::db($expires)]);
        BookingHold::query()->updateOrCreate(['reference' => $reference], ['tenant_id' => $this->context->id(), 'expires_at' => Instant::db($expires)]);
        $name = $booking->customer_name;
        $what = $booking instanceof ClassAttendee ? 'Class: '.$booking->session()->title : 'Booking deposit';

        // The return address carries the reference (Yoco and PayFast don't add it).
        return $this->payments->start($reference, $amountCents, $what, ['name' => $name, 'email' => $email],
            ['return' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.rawurlencode($reference), 'cancel' => (string) preg_replace('#/book/return.*$#', '/', $returnUrl)]); // cancelling goes back to the home page
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
            if (! $result['paid'] || $result['amount_cents'] < $booking->deposit_cents) {
                return $booking;
            }
            if ($booking->status !== 'pending_payment') { // the hold expired before payment arrived
                $refunded = $this->payments->refund($reference);
                $booking->update(['paid_at' => Instant::db(now()), 'refunded_at' => $refunded ? Instant::db(now()) : null]);
                $this->audit->record('bookings.deposit_refunded_after_expiry', 'booking', $booking->id, ['refunded' => $refunded]);

                return $booking->refresh();
            }
            $booking->update(['status' => 'confirmed', 'paid_at' => Instant::db(now()), 'hold_expires_at' => null]);
            BookingHold::query()->whereKey($reference)->delete();
            $this->audit->record('bookings.deposit_paid', $booking instanceof ClassAttendee ? 'class_attendee' : 'booking', $booking->id, ['cents' => $booking->deposit_cents]);
            event($booking instanceof ClassAttendee ? new ClassJoined($this->context->id(), $booking->id) : new BookingConfirmed($this->context->id(), $booking->id));

            return $booking->refresh();
        });
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
                        $n++;
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
