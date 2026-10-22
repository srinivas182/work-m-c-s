@php($r = fn (int $c) => 'R'.number_format($c / 100, 2, '.', ' '))
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 36px 40px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1B1640; }
  h1 { font-size: 22px; margin: 0 0 2px; letter-spacing: -0.3px; }
  .muted { color: #5b5776; }
  .row { width: 100%; }
  .row td { vertical-align: top; }
  table.lines { width: 100%; border-collapse: collapse; margin-top: 24px; }
  table.lines th { text-align: left; font-size: 9.5px; color: #5b5776; border-bottom: 1px solid #d9d6e8; padding: 6px 4px; }
  table.lines td { border-bottom: 1px solid #eeecf5; padding: 8px 4px; }
  .num { text-align: right; white-space: nowrap; }
  .totals { width: 46%; margin-left: 54%; margin-top: 14px; border-collapse: collapse; }
  .totals td { padding: 4px; }
  .grand td { font-weight: bold; font-size: 12px; border-top: 1.5px solid #1B1640; padding-top: 7px; }
  .draft { color: #8A1C1C; border: 1px solid #8A1C1C; padding: 6px 8px; margin-bottom: 14px; font-size: 9.5px; }
  .box { background: #F4F2FB; padding: 10px 12px; }
</style>
</head>
<body>
@if ($placeholder)
  <div class="draft">The supplier details on this document are placeholders and must be updated before invoices are issued to customers.</div>
@endif
<table class="row">
  <tr>
    <td style="width:55%">
      <h1>Tax Invoice</h1>
      <div class="muted">{{ $invoice->number }} · {{ $invoice->issued_at->format('j F Y') }}</div>
    </td>
    <td style="width:45%; text-align:right">
      <strong>{{ $invoice->issuer['name'] }}</strong><br>
      {{ $invoice->issuer['address'] }}<br>
      VAT number: {{ $invoice->issuer['vat_number'] }}
      @if (! empty($invoice->issuer['registration']))<br>Registration: {{ $invoice->issuer['registration'] }}@endif
    </td>
  </tr>
</table>
<div class="box" style="margin-top:22px">
  <span class="muted">Billed to</span><br>
  <strong>{{ $invoice->customer['name'] }}</strong><br>
  @if (! empty($invoice->customer['address'])){{ $invoice->customer['address'] }}<br>@endif
  {{ $invoice->customer['email'] }}
  @if (! empty($invoice->customer['vat_number']))<br>VAT number: {{ $invoice->customer['vat_number'] }}@endif
</div>
<table class="lines">
  <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Excl. VAT</th><th class="num">VAT ({{ rtrim(rtrim(number_format($invoice->vat_bp / 100, 2), '0'), '.') }}%)</th><th class="num">Total</th></tr></thead>
  <tbody>
  @foreach ($lines as $line)
    <tr><td>{{ $line->description }}</td><td class="num">{{ $line->quantity }}</td><td class="num">{{ $r($line->excl_cents) }}</td><td class="num">{{ $r($line->vat_cents) }}</td><td class="num">{{ $r($line->total_cents) }}</td></tr>
  @endforeach
  </tbody>
</table>
<table class="totals">
  <tr><td>Total excl. VAT</td><td class="num">{{ $r($invoice->excl_cents) }}</td></tr>
  <tr><td>VAT</td><td class="num">{{ $r($invoice->vat_cents) }}</td></tr>
  <tr class="grand"><td>Total ({{ $invoice->currency }})</td><td class="num">{{ $r($invoice->total_cents) }}</td></tr>
  <tr><td class="muted">Paid</td><td class="num muted">{{ $r($invoice->total_cents) }}</td></tr>
</table>
@if ($credits->isNotEmpty())
  <p style="margin-top:20px"><strong>Credit notes</strong></p>
  @foreach ($credits as $credit)
    <div class="muted">{{ $credit->number }} · {{ $credit->issued_at->format('j F Y') }} · {{ $r($credit->total_cents) }} · {{ $credit->reason }}</div>
  @endforeach
@endif
</body>
</html>
