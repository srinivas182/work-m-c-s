{{-- Share kit poster (Sprint 23.3): A4 or A5 portrait, or a table tent (A4 landscape, folded in half: the top half upside down so both sides read). --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #111; }
  .face { text-align: center; padding: {{ $size === 'a5' ? '14mm 12mm' : '20mm 18mm' }}; }
  .band { background: {{ $primary }}; color: {{ $on }}; padding: {{ $size === 'a5' ? '7mm' : '10mm' }}; border-radius: 6mm; }
  .business { font-size: {{ $size === 'a5' ? '20pt' : ($size === 'tent' ? '20pt' : '28pt') }}; font-weight: bold; margin: 0; }
  .call { font-size: {{ $size === 'a5' ? '24pt' : ($size === 'tent' ? '22pt' : '36pt') }}; font-weight: bold; margin: {{ $size === 'tent' ? '4mm' : '10mm' }} 0 2mm; }
  .detail { font-size: {{ $size === 'tent' ? '11pt' : '14pt' }}; margin: 0 0 4mm; color: #333; }
  .qr { width: {{ $size === 'a5' ? '80mm' : '120mm' }}; height: auto; }
  .address { font-size: {{ $size === 'tent' ? '10pt' : '13pt' }}; margin-top: 3mm; color: #333; }
  .logo { max-height: {{ $size === 'tent' ? '14mm' : '24mm' }}; max-width: 70mm; margin-bottom: 4mm; }
  .tent { position: absolute; left: 0; width: 297mm; height: 105mm; overflow: hidden; padding: 0; }
  .tent .inner { width: 200mm; margin: 0 auto; padding-top: 4mm; }
  .tent .band { padding: 3mm; border-radius: 3mm; }
  .tent .call { margin: 3mm 0 1mm; }
  .tent .qr { width: 44mm; }
  .tent .address { margin-top: 1mm; }
  .top { top: 0; transform: rotate(180deg); transform-origin: 50% 50%; }
  .bottom { top: 105mm; }
  .fold { position: absolute; left: 0; top: 105mm; width: 297mm; border-top: 0.3mm dashed #999; }
</style>
</head>
<body>
@php
  $face = function () use ($business, $call, $detail, $address, $qr, $logo) {
      return view('website::share.poster-face', compact('business', 'call', 'detail', 'address', 'qr', 'logo'))->render();
  };
@endphp
@if ($size === 'tent')
  <div class="face tent top"><div class="inner">{!! $face() !!}</div></div>
  <div class="fold"></div>
  <div class="face tent bottom"><div class="inner">{!! $face() !!}</div></div>
@else
  <div class="face">{!! $face() !!}</div>
@endif
</body>
</html>
