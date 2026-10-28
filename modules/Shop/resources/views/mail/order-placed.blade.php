Thank you for your order with {{ $business }}.

Order #{{ $number }}
@foreach ($lines as $line)
- {{ $line }}
@endforeach
Total (incl. VAT): {{ $total }}

@if ($howToPay)
How to pay — use #{{ $number }} as your reference:
{{ $howToPay }}

@endif
Your order: {{ $link }}
