<?php

declare(strict_types=1);

namespace Wurk\Shop\Assistant;

use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Platform\Contracts\AssistantActionTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Platform\Money\Money;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderManager;

/**
 * Ask WURK (26.1): "mark order 1042 paid cash". The same step as Orders → Mark paid (Sprint 22.3): a pay-later order,
 * by EFT or in person (cash, card machine, QR), recorded with who took it. Payments can't be undone here: a mistake
 * is put right with a refund on the order.
 */
final readonly class MarkOrderPaidTool implements AssistantActionTool
{
    public const HOW = ['eft' => 'EFT', ...InPersonPayments::METHODS];

    public function __construct(private OrderManager $orders) {}

    public function name(): string
    {
        return 'mark_order_paid';
    }

    public function description(): string
    {
        return 'Mark a pay-later shop order as paid, saying how: cash, card machine, QR or EFT.';
    }

    public function parameters(): array
    {
        return ['order' => 'the order number, e.g. 1042', 'how' => implode(' | ', array_keys(self::HOW))];
    }

    public function permission(): string
    {
        return 'shop.orders';
    }

    public function entitlement(): string
    {
        return 'shop';
    }

    public function preview(array $params): array
    {
        [$order, $how] = $this->check($params);

        return ['summary' => "Mark order #{$order->number} paid — ".self::HOW[$how],
            'changes' => ["{$order->customer_name}: ".Money::format($order->dueCents()), $how === 'eft' ? 'Paid into your bank account' : 'Taken in person — counted in today’s cash-up']];
    }

    public function apply(array $params): array
    {
        [$order, $how] = $this->check($params);
        $user = auth()->user();
        try {
            $this->orders->advance($order, 'paid', (string) (data_get($user, 'name') ?? 'Ask WURK'), $how, $user === null ? null : (string) $user->getAuthIdentifier());
        } catch (CheckoutProblem $e) {
            throw new AssistantRefused($e->getMessage());
        }

        return ['done' => "Order #{$order->number} is marked paid (".self::HOW[$how].').', 'link' => route('tenant.shop.orders.show', ['order' => $order->id]), 'undo' => []];
    }

    public function undo(array $undo): bool
    {
        return false; // a payment is put right with a refund on the order
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{Order, string}
     */
    private function check(array $params): array
    {
        $number = (int) preg_replace('/\D/', '', (string) ($params['order'] ?? ''));
        $order = $number > 0 ? Order::query()->where('number', $number)->first() : null;
        if ($order === null) {
            throw new AssistantRefused($number > 0 ? "I can’t find order #{$number}." : 'Which order number?');
        }
        $how = strtolower(str_replace([' ', '-'], '_', trim((string) ($params['how'] ?? ''))));
        $how = ['card' => 'card_machine', 'snapscan' => 'qr', 'zapper' => 'qr', 'bank' => 'eft', 'transfer' => 'eft'][$how] ?? $how;
        if (! isset(self::HOW[$how])) {
            throw new AssistantRefused('How was it paid — cash, card machine, QR or EFT?');
        }
        if ($order->paid_at !== null) {
            throw new AssistantRefused("Order #{$order->number} is already paid.");
        }
        if ($order->payment_method !== 'offline' || $order->status !== 'awaiting_payment') {
            throw new AssistantRefused("Order #{$order->number} isn’t waiting for a payment to be recorded.");
        }

        return [$order, $how];
    }
}
