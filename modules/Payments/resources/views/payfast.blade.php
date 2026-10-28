<!doctype html>
<html lang="en-ZA">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Taking you to PayFast · {{ $business }}</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;color:#1b1640}main{text-align:center;padding:2rem}button{font:inherit;padding:.7rem 1.2rem;border-radius:8px;border:0;background:#1b1640;color:#fff;font-weight:600}</style>
</head>
<body>
<main>
  <p>{{ $business }}</p>
  <h1>Taking you to PayFast to pay…</h1>
  <form method="post" action="{{ $action }}">
    @foreach ($fields as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <noscript><p>Press the button to continue to PayFast.</p></noscript>
    <button type="submit">Continue to PayFast</button>
  </form>
</main>
<script>document.forms[0].submit();</script>
</body>
</html>
