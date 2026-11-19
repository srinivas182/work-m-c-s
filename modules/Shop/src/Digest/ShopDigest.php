<?php

declare(strict_types=1);

namespace Wurk\Shop\Digest;

use Carbon\CarbonImmutable;
use Wurk\Platform\Contracts\OwnerDigestRefused;
use Wurk\Platform\Contracts\OwnerDigestSource;
use Wurk\Platform\Money\Money;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderManager;

/**
 * The owner's evening summary (21.6): today's orders and payments, and EFT orders waiting to be marked paid — the
 * owner replies with the number once the money shows in the bank (or declines to cancel the order).
 */
final readonly class ShopDigest implements OwnerDigestSource
{
    public function __construct(private OrderManager $orders) {}

    public function key(): string
    {
        return 'order';
    }

    public function figures(CarbonImmutable $today): array
    {
        $from = $today->utc();
        $to = $today->addDay()->utc();

        return [
            'new_orders' => Order::query()->whereNotIn('status', ['expired', 'cancelled'])->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'received_cents' => (int) Order::query()->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('total_cents'),
        ];
    }

    public function requests(): array
    {
        return array_values(Order::query()->where('status', 'awaiting_payment')->where('payment_method', 'offline')->orderBy('created_at')->limit(5)->get()
            ->map(fn (Order $o) => ['id' => $o->id, 'label' => "Mark order #{$o->number} (".self::money($o->total_cents).", {$o->customer_name}) as paid by EFT?"])->all());
    }

    public function decide(string $id, bool $yes, string $identityId, string $name): string
    {
        $order = Order::query()->find($id) ?? throw new OwnerDigestRefused('That order isn’t there any more.');
        if ($order->status !== 'awaiting_payment') {
            throw new OwnerDigestRefused("Order #{$order->number} has already been dealt with.");
        }
        try {
            if ($yes) {
                $this->orders->advance($order, 'paid', $identityId);

                return "Order #{$order->number} is marked paid.";
            }
            $this->orders->cancel($order, $identityId, 'Declined on WhatsApp: payment not received');

            return "Order #{$order->number} is cancelled and its stock is back.";
        } catch (CheckoutProblem $e) {
            throw new OwnerDigestRefused($e->getMessage());
        }
    }

    private static function money(int $cents): string
    {
        return Money::format((int) $cents);
    }
}
