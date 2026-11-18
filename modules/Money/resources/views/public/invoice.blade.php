<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $label }} · {{ $business }}</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 36rem; margin: 0 auto; padding: 2rem 1rem; }
  h1 { font-size: 1.5rem; margin: .25rem 0 1rem; }
  table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
  th, td { text-align: left; padding: .5rem .25rem; border-bottom: 1px solid #e5e3ef; }
  td.n, th.n { text-align: right; }
  .total td { font-weight: 700; border-bottom: 0; }
  .status { display: inline-block; padding: .25rem .6rem; border-radius: 999px; background: #ecebf5; font-size: .875rem; }
  .paid { background: #dcfce7; color: #14532d; }
  button { font: inherit; font-weight: 600; padding: .8rem 1.4rem; border: 0; border-radius: .5rem; background: #1b1640; color: #fff; cursor: pointer; width: 100%; }
  p { color: #4b4766; line-height: 1.5; }
  pre { white-space: pre-wrap; font: inherit; background: #fff; border: 1px solid #e5e3ef; border-radius: .5rem; padding: .75rem; }
</style>
</head>
<body>
<main>
  <p>{{ $business }}</p>
  <h1>Invoice {{ $label }}</h1>
  <p>For {{ $customer }} · issued {{ $issued }} · due {{ $due }}@if ($vatNumber) · VAT number {{ $vatNumber }}@endif</p>
  @if ($paid)<p><span class="status paid">Paid {{ $paidOn }}</span></p>@elseif ($void)<p><span class="status">Cancelled</span></p>@endif
  <table>
    <thead><tr><th>Item</th><th class="n">Qty</th><th class="n">Amount</th></tr></thead>
    <tbody>
    @foreach ($lines as $l)
      <tr><td>{{ $l['description'] }}</td><td class="n">{{ $l['quantity'] }}</td><td class="n">{{ $l['amount'] }}</td></tr>
    @endforeach
    @if ($vat !== null)
      <tr><td colspan="2">VAT (15%)</td><td class="n">{{ $vat }}</td></tr>
    @endif
      <tr class="total"><td colspan="2">Total</td><td class="n">{{ $total }}</td></tr>
    </tbody>
  </table>
  @if ($message)<p role="status">{{ $message }}</p>@endif
  @if (! $paid && ! $void)
    @if ($canPayOnline)
      <form method="post" action="{{ $payUrl }}">
        <button type="submit">Pay {{ $total }}</button>
      </form>
    @endif
    @if ($eft)
      <p>Or pay by EFT:</p>
      <pre>{{ $eft }}
Reference: {{ $label }}</pre>
    @endif
  @endif
  <p><a href="/">Visit {{ $business }}’s website</a></p>
</main>
</body>
</html>
