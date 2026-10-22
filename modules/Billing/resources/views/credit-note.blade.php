@php($r = fn (int $c) => 'R'.number_format($c / 100, 2, '.', ' '))
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 36px 40px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1B1640; }
  h1 { font-size: 22px; margin: 0 0 2px; }
  .muted { color: #5b5776; }
  td { vertical-align: top; }
  .totals { width: 46%; margin-left: 54%; margin-top: 18px; border-collapse: collapse; }
  .totals td { padding: 4px; }
  .num { text-align: right; }
  .grand td { font-weight: bold; border-top: 1.5px solid #1B1640; padding-top: 7px; }
  .box { background: #F4F2FB; padding: 10px 12px; margin-top: 22px; }
</style>
</head>
<body>
<table style="width:100%">
  <tr>
    <td style="width:55%"><h1>Credit Note</h1><div class="muted">{{ $note->number }} · {{ $note->issued_at->format('j F Y') }}</div><div class="muted">Against tax invoice {{ $invoice->number }}</div></td>
    <td style="width:45%; text-align:right"><strong>{{ $invoice->issuer['name'] }}</strong><br>{{ $invoice->issuer['address'] }}<br>VAT number: {{ $invoice->issuer['vat_number'] }}</td>
  </tr>
</table>
<div class="box"><span class="muted">Issued to</span><br><strong>{{ $invoice->customer['name'] }}</strong><br>{{ $invoice->customer['email'] }}@if (! empty($invoice->customer['vat_number']))<br>VAT number: {{ $invoice->customer['vat_number'] }}@endif</div>
<p style="margin-top:18px"><strong>Reason:</strong> {{ $note->reason }}</p>
<table class="totals">
  <tr><td>Credit excl. VAT</td><td class="num">{{ $r($note->excl_cents) }}</td></tr>
  <tr><td>VAT</td><td class="num">{{ $r($note->vat_cents) }}</td></tr>
  <tr class="grand"><td>Total credited</td><td class="num">{{ $r($note->total_cents) }}</td></tr>
</table>
<p class="muted" style="margin-top:18px">{{ $note->refund === 'card' ? 'Refunded to the card used for the original payment.' : ($note->refund === 'eft' ? 'Refunded by EFT to your bank account.' : 'No refund due.') }}</p>
</body>
</html>
