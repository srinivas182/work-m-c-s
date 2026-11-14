@php($i = $incident)
{{ \Wurk\Watchtower\Mail\IncidentMail::REASONS[$reason] ?? 'Problem' }} — {{ $i->reference() }}

{!! $i->title !!}

@if ($reason === 'recovered' && $downMinutes !== null)
{!! \Illuminate\Support\Str::after((string) $incident->location, 'monitor ') !!} is back up after {{ $downMinutes }} minute{{ $downMinutes === 1 ? '' : 's' }} down.
@if ($during !== [])

What Watchtower saw while it was down (usually the reason):
@foreach ($during as $d)
- [{{ \Wurk\Watchtower\Models\Incident::SEVERITIES[$d->severity] ?? $d->severity }}] {!! $d->title !!} — {{ $d->occurrences }}× ({{ $d->reference() }})
@endforeach
@else
Watchtower saw no errors inside WURK meanwhile — look at the network, DNS, load balancer or the servers themselves.
@endif
@elseif ($reason === 'recovered')
This is working again{{ $i->resolved_at ? ' since '.$i->resolved_at->copy()->setTimezone('Africa/Johannesburg')->format('H:i').' SAST' : '' }}. It started {{ $i->first_seen_at->copy()->setTimezone('Africa/Johannesburg')->format('j M H:i') }} SAST.
@elseif ($reason === 'regression')
This was marked fixed{{ $i->fixed_in ? ' in '.$i->fixed_in : '' }} and has happened again in {{ $i->last_release }}.
@elseif ($reason === 'spike')
It's now happened {{ $i->occurrences }} times — ten times more than when we last told you.
@elseif ($reason === 'worse')
Its severity has gone up to {{ \Wurk\Watchtower\Models\Incident::SEVERITIES[$i->severity] ?? $i->severity }}.
@endif

@if (($incident->analysis['status'] ?? null) === 'done')

What WURK's AI thinks ({{ $incident->analysis['confidence'] ?? 'low' }} confidence):
{!! $incident->analysis['summary'] !!}
@if (($incident->analysis['cause'] ?? '') !== '')
Likely cause: {!! $incident->analysis['cause'] !!}
@endif
@endif

Open it in Admin: {!! $link !!}

To get it fixed, paste everything between the lines into your engineering chat:

----------------------------------------------------------------------
{!! $fixRequest !!}
----------------------------------------------------------------------

WURK Watchtower
