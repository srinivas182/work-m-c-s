<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Shop\Models\Order;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Card-paid orders: confirmed only once the payment is verified with the provider (from the notification
 * or when the customer comes back). A payment that arrives after the stock hold expired is refunded.
 */
final readonly class OrderPayments
{
    public function __construct(private BusinessPayments $payments, private AuditLog $audit) {}

    public function handle(BusinessPaymentReceived $event): void
    {
        if (! str_starts_with($event->reference, 'shop-')) {
            return; // someone else's payment (a deposit, a class)
        }
        $tenant = app(TenantDirectory::class)->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($event): void {
            $order = Order::query()->whereKey(substr($event->reference, strlen('shop-')))->first();
            if ($order !== null) {
                $this->settle($order);
            }
        });
    }

    /** Verify and confirm (safe to call more than once). */
    public function settle(Order $order): void
    {
        $reference = 'shop-'.$order->id;
        $result = $this->payments->verify($reference);
        if (! $result['paid'] || $result['amount_cents'] < $order->total_cents) {
            return;
        }
        if ($order->status === 'awaiting_payment') {
            $order->update(['status' => 'paid', 'hold_expires_at' => null, 'paid_at' => now()]);
            app(OrderManager::class)->log($order, 'paid', 'Payment provider');
            event(new OrderStatusChanged(app(TenantContext::class)->id(), $order->id, 'paid'));
            DB::table('shop_holds')->where('order_id', $order->id)->delete();
            $this->audit->record('shop.order_paid', 'order', $order->id, ['cents' => $order->total_cents]);
        } elseif (in_array($order->status, ['expired', 'cancelled'], true)) {
            // Paid after the stock went back: refund rather than sell what may be gone.
            $refunded = $this->payments->refund($reference);
            $this->audit->record($refunded ? 'shop.late_payment_refunded' : 'shop.late_payment_refund_failed', 'order', $order->id, []);
        }
    }
}
