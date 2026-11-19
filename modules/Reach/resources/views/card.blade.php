{{-- Bookings by phone (25.2b): four counter cards on an A4 page — how to book from any phone, no data needed. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Book from any phone') }} · {{ $business }}</title>
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #111; background: #fff; }
  .sheet { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 148.5mm 148.5mm; width: 210mm; height: 297mm; }
  .card { border: 0.3mm dashed #999; padding: 12mm 10mm; display: flex; flex-direction: column; justify-content: center; text-align: center; gap: 4mm; }
  h1 { font-size: 18pt; margin: 0; }
  .lead { font-size: 12pt; margin: 0; color: #333; }
  .how { font-size: 20pt; font-weight: 700; margin: 0; letter-spacing: 0.02em; word-break: break-all; }
  .small { font-size: 10pt; color: #444; margin: 0; }
</style>
</head>
<body>
<main class="sheet" aria-label="{{ __('Counter cards') }}">
  @for ($i = 0; $i < 4; $i++)
    <div class="card">
      <h1>{{ $business }}</h1>
      <p class="lead">{{ __('Book from any phone — no data needed') }}</p>
      @if ($how['dial'])
        <p class="small">{{ __('Dial') }}</p>
        <p class="how">{{ $how['dial'] }}</p>
      @endif
      @if ($how['sms'])
        <p class="small">{{ $how['dial'] ? __('or') : '' }}</p>
        <p class="how" style="font-size: 15pt">{{ $how['sms'] }}</p>
      @endif
      @if (! $how['dial'] && ! $how['sms'])
        <p class="small">{{ __('Bookings by phone are switched off.') }}</p>
      @endif
    </div>
  @endfor
</main>
</body>
</html>
