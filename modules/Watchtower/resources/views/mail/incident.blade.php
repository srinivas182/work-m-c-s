@php($i = $incident)
{{ \Wurk\Watchtower\Mail\IncidentMail::REASONS[$reason] ?? 'Problem' }} — {{ $i->reference() }}

{!! $i->title !!}

@if ($reason === 'recovered')
This is working again{{ $i->resolved_at ? ' since '.$i->resolved_at->copy()->setTimezone('Africa/Johannesburg')->format('H:i').' SAST' : '' }}. It started {{ $i->first_seen_at->copy()->setTimezone('Africa/Johannesburg')->format('j M H:i') }} SAST.
@elseif ($reason === 'regression')
This was marked fixed{{ $i->fixed_in ? ' in '.$i->fixed_in : '' }} and has happened again in {{ $i->last_release }}.
@elseif ($reason === 'spike')
It's now happened {{ $i->occurrences }} times — ten times more than when we last told you.
@elseif ($reason === 'worse')
Its severity has gone up to {{ \Wurk\Watchtower\Models\Incident::SEVERITIES[$i->severity] ?? $i->severity }}.
@endif

Open it in Admin: {!! $link !!}

To get it fixed, paste everything between the lines into your engineering chat:

----------------------------------------------------------------------
{!! $fixRequest !!}
----------------------------------------------------------------------

WURK Watchtower
