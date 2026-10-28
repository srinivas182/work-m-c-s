<?php

declare(strict_types=1);

namespace Wurk\Shop\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderManager;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Shop → Orders — the list, one order with its timeline, and what can be done with it now. */
final class OrdersController
{
    /** The list's filters: label => statuses. */
    private const FILTERS = [
        'todo' => ['paid', 'packed'],
        'awaiting' => ['awaiting_payment'],
        'sent' => ['ready', 'out_for_delivery'],
        'done' => ['done'],
        'cancelled' => ['cancelled', 'expired'],
    ];

    public function __construct(private TenantContext $context, private OrderManager $orders) {}

    public function index(Request $request): Response
    {
        $filter = (string) $request->query('show', 'todo');
        $query = Order::query()->orderByDesc('created_at');
        if (isset(self::FILTERS[$filter])) {
            $query->whereIn('status', self::FILTERS[$filter]);
        }
        $tz = $this->context->current()->timezone();

        return Inertia::render('Shop::Orders', [
            'show' => isset(self::FILTERS[$filter]) ? $filter : 'all',
            'counts' => collect(self::FILTERS)->map(fn (array $s) => Order::query()->whereIn('status', $s)->count())->all(),
            'orders' => $query->limit(100)->get()->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'customer' => $o->customer_name, 'total_cents' => $o->total_cents, 'status' => $o->status,
                'payment' => $o->payment_method, 'fulfilment' => $o->fulfilment, 'placed' => $o->created_at->setTimezone($tz)->format('j M, H:i'),
            ])->all(),
        ]);
    }

    public function show(string $order): Response
    {
        $o = Order::query()->whereKey($order)->firstOrFail();
        $tz = $this->context->current()->timezone();
        $next = match ($o->status) {
            'awaiting_payment' => $o->payment_method === 'offline' ? ['paid'] : [],
            'paid' => ['packed', $o->fulfilment === 'delivery' ? 'out_for_delivery' : 'ready'],
            'packed' => [$o->fulfilment === 'delivery' ? 'out_for_delivery' : 'ready'],
            'ready', 'out_for_delivery' => ['done'],
            default => [],
        };

        return Inertia::render('Shop::Order', ['order' => [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'payment' => $o->payment_method, 'fulfilment' => $o->fulfilment,
            'customer' => ['name' => $o->customer_name, 'phone' => $o->customer_phone, 'email' => $o->customer_email],
            'address' => $o->delivery_address, 'notes' => $o->notes,
            'subtotal_cents' => $o->subtotal_cents, 'delivery_cents' => $o->delivery_cents, 'total_cents' => $o->total_cents, 'refunded_cents' => $o->refunded_cents,
            'items' => OrderItem::query()->where('order_id', $o->id)->get()->map(fn (OrderItem $i) => [
                'name' => $i->product_name, 'label' => $i->variant_label, 'quantity' => $i->quantity, 'line_cents' => $i->line_cents,
            ])->all(),
            'timeline' => DB::connection($this->context->connectionName())->table('shop_order_events')->where('order_id', $o->id)->orderBy('id')->get()
                ->map(fn ($e) => ['event' => $e->event, 'by' => $e->by, 'note' => $e->note, 'amount_cents' => $e->amount_cents,
                    'at' => CarbonImmutable::parse((string) $e->created_at)->setTimezone($tz)->format('j M, H:i')])->all(),
            'next' => $next,
            'can_cancel' => in_array($o->status, OrderManager::CANCELLABLE, true),
            'can_refund' => ! in_array($o->status, ['awaiting_payment', 'expired'], true) && $o->refunded_cents < $o->total_cents
                && ! ($o->status === 'cancelled' && $o->payment_method === 'offline' && $o->paid_at === null),
        ]]);
    }

    public function advance(Request $request, string $order): RedirectResponse
    {
        $to = (string) $request->validate(['to' => ['required', 'in:paid,packed,ready,out_for_delivery,done']])['to'];

        return $this->attempt(fn () => $this->orders->advance($this->find($order), $to, $this->who($request)),
            ['paid' => 'Marked as paid.', 'packed' => 'Marked as packed.', 'ready' => 'Marked ready — the customer has been told.', 'out_for_delivery' => 'Marked as on its way — the customer has been told.', 'done' => 'Marked as done.'][$to]);
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null;
        $o = $this->find($order);
        $refunded = false;
        $response = $this->attempt(function () use ($o, $request, $reason, &$refunded): void {
            $refunded = $this->orders->cancel($o, $this->who($request), $reason);
        }, '');

        return $response->with('status', $refunded ? 'Order cancelled — the stock is back and the card payment is being refunded.' : 'Order cancelled — the stock is back.');
    }

    public function refund(Request $request, string $order): RedirectResponse
    {
        $data = $request->validate(['amount_cents' => ['nullable', 'integer', 'min:1'], 'restock' => ['boolean'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(fn () => $this->orders->refund($this->find($order), $data['amount_cents'] ?? null, (bool) ($data['restock'] ?? false), $this->who($request), $data['note'] ?? null),
            'Refund recorded — the customer has been told.');
    }

    private function find(string $order): Order
    {
        return Order::query()->whereKey($order)->firstOrFail();
    }

    private function who(Request $request): string
    {
        $user = $request->user();
        $name = trim((string) data_get($user, 'first_name').' '.(string) data_get($user, 'last_name'));

        return $name !== '' ? $name : 'The business';
    }

    /** Run an action; the order manager's explanations become a form error. */
    private function attempt(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (CheckoutProblem $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
