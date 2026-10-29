<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Order #{{ $order->number }} · {{ $business }}</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 36rem; margin: 0 auto; padding: 2rem 1.25rem; display: grid; gap: 1.25rem; }
  h1 { font-size: 1.6rem; margin: 0; } h2 { font-size: 1.1rem; margin: 0 0 .5rem; }
  p { color: #4b4766; line-height: 1.5; margin: 0; }
  .card { background: #fff; border: 1px solid #e3e1ee; border-radius: 12px; padding: 1rem 1.25rem; display: grid; gap: .6rem; }
  table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: .35rem 0; } td:last-child, th:last-child { text-align: right; }
  .pre { white-space: pre-line; }
  a { color: #1b1640; font-weight: 600; }
</style>
</head>
<body>
<main>
  <p>{{ $business }}</p>
  <h1>Order #{{ $order->number }}</h1>
  <p role="status"><strong>{{ ['awaiting_payment' => 'Waiting for payment', 'paid' => 'Paid — thank you', 'packed' => 'Being packed', 'ready' => 'Ready for collection', 'out_for_delivery' => 'On its way', 'done' => 'Done', 'cancelled' => 'Cancelled', 'expired' => 'Cancelled: payment didn’t arrive in time'][$order->status] ?? $order->status }}</strong> · placed {{ $placed }}</p>

  @if ($instructions)
  <section class="card" aria-label="How to pay">
    <h2>How to pay</h2>
    <p class="pre">{{ $instructions }}</p>
        <p>Use <strong>#{{ $order->number }}</strong> as your reference.</p>
  </section>
  @endif

  @if ($order->tracking_reference)
  <section class="card" aria-label="Tracking">
    <h2>{{ $order->shipping_provider === 'pargo' ? 'On its way to your pickup point' : 'On its way' }}</h2>
    @if ($order->pickup_point_name)<p>Collect from: {{ $order->pickup_point_name }}</p>@endif
    <p>Tracking number: <strong>{{ $order->tracking_reference }}</strong></p>
    @if ($order->tracking_url && str_starts_with($order->tracking_url, 'https://'))<p><a href="{{ $order->tracking_url }}" rel="noreferrer">Track your parcel</a></p>@endif
  </section>
  @endif


  <section class="card" aria-label="Your order">
    <h2>Your order</h2>
    <table>
      <caption class="sr-only" style="position:absolute;left:-9999px">Items, quantities and prices</caption>
      <thead><tr><th scope="col">Item</th><th scope="col">Price</th></tr></thead>
      <tbody>
      @foreach ($items as $item)
        <tr><td>{{ $item->quantity }} × {{ $item->product_name }}@if ($item->variant_label) ({{ $item->variant_label }})@endif</td><td>R{{ number_format($item->line_cents / 100, 2) }}</td></tr>
      @endforeach
      @if ($order->delivery_cents > 0)
        <tr><td>Delivery</td><td>R{{ number_format($order->delivery_cents / 100, 2) }}</td></tr>
      @endif
        <tr><th scope="row">Total (incl. VAT)</th><th>R{{ number_format($order->total_cents / 100, 2) }}</th></tr>
      </tbody>
    </table>
  </section>

  <section class="card" aria-label="{{ $order->fulfilment === 'delivery' ? 'Delivery' : 'Collection' }}">
    <h2>{{ $order->fulfilment === 'delivery' ? 'Delivery' : 'Collection' }}</h2>
    <p class="pre">{{ $order->fulfilment === 'delivery' ? $order->delivery_address : ($collection ?: 'We’ll let you know when it’s ready to collect.') }}</p>
  </section>
  <p><a href="/">Back to the website</a></p>
</main>
</body>
</html>
