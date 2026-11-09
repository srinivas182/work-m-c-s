Your {{ $span }} for {{ $business }}.

@if (count($highlights) > 0)
@foreach ($highlights as $h)
- {{ $h }}
@endforeach

@endif
@foreach ($sections as $s)
{{ $s['title'] }}
@foreach ($s['lines'] as $l)
  {{ $l }}
@endforeach

@endforeach
The full report: {{ $link }}

You can change or stop this email on the Reports page.
WURK
