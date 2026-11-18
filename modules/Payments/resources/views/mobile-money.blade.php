<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
@if ($state === 'pending')<meta http-equiv="refresh" content="4">@endif
<title>Pay {{ $amount }} with {{ $provider }} · {{ $business }}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;color:#1b1640;background:#faf9fc}
main{width:min(26rem,100% - 2rem);padding:2rem 1.5rem;background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(27,22,64,.08);text-align:center}
h1{font-size:1.4rem;margin:.25rem 0 1rem}p{line-height:1.5}.muted{color:#5b5677;font-size:.95rem}
label{display:block;text-align:left;font-weight:600;margin:1rem 0 .4rem}
input{box-sizing:border-box;width:100%;font:inherit;font-size:1.15rem;padding:.75rem;border:1px solid #c9c5da;border-radius:10px}
button{margin-top:1rem;width:100%;font:inherit;font-weight:600;padding:.85rem;border-radius:10px;border:0;background:#1b1640;color:#fff}
.alert{background:#fdecec;color:#8a1c1c;border-radius:10px;padding:.75rem;margin:1rem 0;text-align:left}
.wait{width:2.5rem;height:2.5rem;margin:1rem auto;border:4px solid #e3e0ee;border-top-color:#1b1640;border-radius:50%;animation:s 1s linear infinite}
@keyframes s{to{transform:rotate(1turn)}}@media (prefers-reduced-motion:reduce){.wait{animation:none}}
a{color:#1b1640}
</style>
</head>
<body>
<main>
  <p class="muted">{{ $business }}</p>
  @if ($state === 'pending')
    <h1>Check your phone</h1>
    <div class="wait" aria-hidden="true"></div>
    <p role="status">Approve the payment of <strong>{{ $amount }}</strong> with your {{ $provider }} PIN on {{ $phone }}.</p>
    <p class="muted">This page updates by itself. Didn’t get the prompt? Wait a moment, then send it again.</p>
    <form method="post" action="{{ $action }}"><input type="hidden" name="phone" value="{{ $phone }}"><button type="submit">Send the prompt again</button></form>
  @else
    <h1>Pay {{ $amount }} with {{ $provider }}</h1>
    @if ($failure)<p class="alert" role="alert">{{ $failure }}</p>@endif
    @if ($error)<p class="alert" role="alert">{{ $error }}</p>@endif
    <form method="post" action="{{ $action }}">
      <label for="phone">Your {{ $provider }} number</label>
      <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ $phone }}">
      <button type="submit">{{ $failure ? 'Try again' : 'Send the payment prompt' }}</button>
    </form>
    <p class="muted">A prompt appears on your phone. Approve it with your PIN to pay.</p>
  @endif
  @if ($cancel !== '')<p><a href="{{ $cancel }}">Choose another way to pay</a></p>@endif
</main>
</body>
</html>
