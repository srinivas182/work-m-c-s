Hi,

These {{ $business }} leads are due for a follow-up:

@foreach ($leads as $lead)
- {{ $lead['title'] }} ({{ $lead['contact'] }}): {{ $lead['url'] }}
@endforeach

— WURK
