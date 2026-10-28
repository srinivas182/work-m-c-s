New order #{{ $number }} from {{ $customer }}.

@foreach ($lines as $line)
- {{ $line }}
@endforeach
Total (incl. VAT): {{ $total }}
{{ $how }}

Open it: {{ $link }}
