<div class="band">
  @if ($logo)<img class="logo" src="{{ $logo }}" alt=""><br>@endif
  <p class="business">{{ $business }}</p>
</div>
<p class="call">{{ $call }}</p>
@if ($detail !== '')<p class="detail">{{ $detail }}</p>@endif
<img class="qr" src="{{ $qr }}" alt="QR code">
<p class="address">{{ $address }}</p>
