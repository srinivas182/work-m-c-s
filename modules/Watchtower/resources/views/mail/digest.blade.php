WURK Watchtower — daily report

@if ($quiet)
All quiet: no new problems in the last 24 hours, nothing open, every system check passing.
@else
@if ($failing !== [])
Failing right now:
@foreach ($failing as $c)
- {{ $c->name }}: {!! $c->message !!}
@endforeach

@endif
@if ($new !== [])
New in the last 24 hours:
@foreach ($new as $i)
- [{{ \Wurk\Watchtower\Models\Incident::SEVERITIES[$i->severity] ?? $i->severity }}] {!! $i->title !!} — {{ $i->occurrences }}× ({{ $i->reference() }})
@endforeach

@endif
Open: {{ $open['critical'] ?? 0 }} critical, {{ $open['high'] ?? 0 }} high, {{ $open['normal'] ?? 0 }} normal. Resolved in the last 24 hours: {{ $resolved }}.
@endif

All problems: {!! $link !!}

WURK Watchtower
