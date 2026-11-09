<!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 18px; margin: 0 0 4px; } h2 { font-size: 14px; margin: 18px 0 6px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 8px; } th, td { text-align: left; padding: 3px 4px; border-bottom: 1px solid #ddd; }
.muted { color: #555; }
</style></head><body>
<h1>{{ $business }} — report</h1>
<p class="muted">{{ $from }} to {{ $to }}, compared with the period before.</p>
@if (count($highlights) > 0)
<h2>Highlights</h2>
<ul>@foreach ($highlights as $h)<li>{{ $h }}</li>@endforeach</ul>
@endif
@foreach ($sections as $s)
<h2>{{ $s['title'] }}</h2>
@if (!empty($s['note']))<p class="muted">{{ $s['note'] }}</p>@endif
<table><tr><th>Figure</th><th>This period</th><th>Previous period</th></tr>
@foreach ($s['metrics'] as $m)
<tr><td>{{ $m['label'] }}</td><td>{{ \Wurk\Reports\Support\ReportExport::value($m, 'value') }}</td><td>{{ !empty($m['snapshot']) ? 'right now' : \Wurk\Reports\Support\ReportExport::value($m, 'previous') }}</td></tr>
@endforeach
</table>
@foreach (['rows', 'extra'] as $t)
@if (!empty($s[$t]['data']))
<table><tr>@foreach ($s[$t]['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr>
@foreach ($s[$t]['data'] as $r)<tr>@foreach ($r as $v)<td>{{ $v }}</td>@endforeach</tr>@endforeach
</table>
@endif
@endforeach
@endforeach
</body></html>
