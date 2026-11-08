Hi {{ $name }},

WURK has paid you R{{ number_format($cents / 100, 2) }} into your {{ $bank }} account.

Bank reference: {{ $reference }}

@if (count($covers) > 0)
This payment covers your commission from:
@foreach ($covers as $c)
- {{ $c['business'] }}: R{{ number_format($c['cents'] / 100, 2) }}
@endforeach
@endif

It can take one to two working days to show in your account.

WURK
