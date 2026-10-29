<?php

declare(strict_types=1);

namespace Wurk\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Mail\OrderPlacedMail;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\BranchStock;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderMessages;
use Wurk\Shop\Support\OrderPayments;
use Wurk\Shop\Support\OrderPlacer;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** The cart and checkout on the business's own address, and each order's signed page. */
final class PublicCheckoutController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private Entitlements $entitlements) {}

    /** Current prices and availability for a cart (the cart never trusts what the browser remembers). */
    public function price(Request $request, string $handle): JsonResponse
    {
        return $this->within($handle, function () use ($request): JsonResponse {
            $data = $request->validate(['branch' => ['nullable', 'string'], 'fulfilment' => ['nullable', 'in:collection,delivery'], 'lines' => ['present', 'array', 'max:30'], 'lines.*.variant' => ['required', 'string'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.OrderPlacer::MAX_QUANTITY]]);
            $stock = app(BranchStock::class);
            $branch = $stock->fulfilling((string) ($data['fulfilment'] ?? 'collection'), $data['branch'] ?? null);
            $variants = Variant::query()->whereIn('id', array_column($data['lines'], 'variant'))->get()->keyBy('id');
            $products = Product::query()->whereIn('id', $variants->pluck('product_id'))->where('status', 'published')->get()->keyBy('id');
            $lines = [];
            foreach ($data['lines'] as $line) {
                $v = $variants[$line['variant']] ?? null;
                $p = $v !== null ? ($products[$v->product_id] ?? null) : null;
                if ($v === null || $p === null) {
                    continue; // gone: dropped from the cart
                }
                $at = $stock->at($v, $branch); // in stock at this branch (null = not tracked)
                $lines[] = ['variant' => $v->id, 'name' => $p->name, 'label' => $v->label, 'slug' => $p->slug, 'photo' => ($p->photos ?? [])[0] ?? null,
                    'unit_cents' => $stock->priceAt($v, $branch), 'quantity' => (int) $line['quantity'], 'available' => $at === null ? OrderPlacer::MAX_QUANTITY : max(0, $at)];
            }
            $s = ShopSettings::query()->find($this->context->id());

            return new JsonResponse(['lines' => $lines, 'subtotal_cents' => array_sum(array_map(fn ($l) => $l['unit_cents'] * $l['quantity'], $lines)), 'checkout' => [
                'available' => app(BusinessPayments::class)->eftInstructions($branch) !== null || app(BusinessPayments::class)->available($branch) !== [],
                'card' => app(BusinessPayments::class)->available($branch) !== [], 'offline' => app(BusinessPayments::class)->eftInstructions($branch) !== null,
                'collection' => $s === null || $s->collection_enabled, 'collection_address' => $s?->collection_address,
                // Several branches: customers choose where to collect from.
                'branches' => count(app(BranchDirectory::class)->open()) > 1 ? array_map(fn (array $b) => ['id' => $b['id'], 'name' => $b['name'], 'address' => $b['address']], app(BranchDirectory::class)->open()) : [],
                'branch' => $branch,
                'delivery' => (bool) $s?->delivery_enabled, 'delivery_fee_cents' => (int) $s?->delivery_fee_cents, 'delivery_note' => $s?->delivery_note,
            ]]);
        });
    }

    public function checkout(Request $request, string $handle, OrderPlacer $placer): JsonResponse
    {
        return $this->within($handle, function (CurrentTenant $tenant) use ($request, $placer): JsonResponse {
            $data = $request->validate([
                'lines' => ['required', 'array', 'min:1', 'max:30'], 'lines.*.variant' => ['required', 'string'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.OrderPlacer::MAX_QUANTITY],
                'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:160'],
                'fulfilment' => ['required', 'in:collection,delivery'], 'branch' => ['nullable', 'string'], 'address' => ['nullable', 'string', 'max:400'], 'notes' => ['nullable', 'string', 'max:1000'],
                'payment' => ['nullable', 'in:card,offline'],
                'consent' => ['accepted'], 'website' => ['nullable', 'string'],
            ], ['consent.accepted' => 'Please agree to be contacted about your order.']);
            $fulfilling = app(BranchStock::class)->fulfilling($data['fulfilment'], $data['branch'] ?? null);
            $method = $data['payment'] ?? (app(BusinessPayments::class)->available($fulfilling) !== [] ? 'card' : 'offline');
            if (($data['website'] ?? '') !== '') {
                return new JsonResponse(['placed' => true], 201); // a bot: no signal, no order
            }
            try {
                $order = $placer->place(array_values(array_map(fn ($l) => ['variant' => (string) $l['variant'], 'quantity' => (int) $l['quantity']], $data['lines'])),
                    ['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null, 'notes' => $data['notes'] ?? null],
                    $data['fulfilment'], $method, $data['branch'] ?? null);
            } catch (CheckoutProblem $e) {
                return new JsonResponse(['message' => $e->getMessage()], 409);
            }

            $url = URL::signedRoute('shop.public.order', ['handle' => $tenant->handle(), 'order' => $order->id]);
            app(OrderMessages::class)->newOrder($order); // the shop's managers are told straight away
            if ($method === 'card') {
                $pay = app(BusinessPayments::class)->start('shop-'.$order->id, $order->total_cents, "Order #{$order->number}",
                    ['name' => $order->customer_name, 'email' => $order->customer_email], ['return' => $url, 'cancel' => $request->getSchemeAndHttpHost().'/cart'], branchId: $order->branch_id);
                if ($pay === null) {
                    $placer->release($order, 'cancelled', null); // can't take payment right now: give the stock straight back

                    return new JsonResponse(['message' => 'Card payment isn’t available right now. Please try again shortly, or contact us.'], 503);
                }

                return new JsonResponse(['placed' => true, 'number' => $order->number, 'total_cents' => $order->total_cents, 'pay_url' => $pay, 'order_url' => $url], 201);
            }
            if ($order->customer_email !== null) {
                $rand = fn (int $c) => 'R'.number_format($c / 100, 2);
                $lines = array_values(OrderItem::query()->where('order_id', $order->id)->get()->map(fn (OrderItem $i) => "{$i->quantity} × {$i->product_name}".($i->variant_label ? " ({$i->variant_label})" : '').' — '.$rand($i->line_cents))->all());
                Mail::to($order->customer_email)->send(new OrderPlacedMail($tenant->displayName(), $order->number, $lines, $rand($order->total_cents),
                    app(BusinessPayments::class)->eftInstructions($order->branch_id), $url));
            }

            return new JsonResponse(['placed' => true, 'number' => $order->number, 'total_cents' => $order->total_cents, 'order_url' => $url], 201);
        });
    }

    /** The customer's page for their order (a signed link): what they ordered and how to pay. */
    public function order(string $handle, string $order): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);

        return $this->context->run($tenant, function () use ($tenant, $order): Response {
            $o = Order::query()->whereKey($order)->firstOrFail();
            if ($o->payment_method === 'card' && $o->status === 'awaiting_payment') {
                app(OrderPayments::class)->settle($o); // back from paying: confirm it now (the notification may follow)
                $o->refresh();
            }
            $s = ShopSettings::query()->find($this->context->id());
            $html = view('shop::public.order', [
                'business' => $tenant->displayName(), 'order' => $o, 'items' => OrderItem::query()->where('order_id', $o->id)->get(),
                'instructions' => $o->status === 'awaiting_payment' && $o->payment_method === 'offline' ? app(BusinessPayments::class)->eftInstructions($o->branch_id) : null,
                'collection' => $o->fulfilment === 'collection' ? $s?->collection_address : null,
                'placed' => $o->created_at->setTimezone($tenant->timezone())->format('j F Y, H:i'),
            ])->render();

            return new Response($html, 200, ['X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
        });
    }

    /** Only for an active business whose package includes the shop. */
    private function within(string $handle, \Closure $fn): JsonResponse
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! $tenant->isActive() || ! $this->entitlements->entitled($tenant->tenantId(), 'shop')) {
            abort(404);
        }

        return $this->context->run($tenant, fn () => $fn($tenant));
    }
}
