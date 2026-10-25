<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $confirmed ? 'You’re booked' : 'Confirming your payment' }} · {{ $business }}</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 32rem; padding: 2rem; text-align: center; }
  h1 { font-size: 1.75rem; margin: 0 0 .75rem; }
  p { color: #4b4766; line-height: 1.5; }
  a { color: #1b1640; font-weight: 600; }
</style>
</head>
<body>
<main>
  <p>{{ $business }}</p>
  @if ($confirmed)
  <h1>You’re booked</h1>
  <p>Your deposit is paid and your booking is confirmed for {{ $when }}. Paystack has emailed your receipt.</p>
  @else
  <h1>We’re still confirming your payment</h1>
  <p>This can take a minute. If your payment went through, your booking will be confirmed and you’ll get a message. If not, no money was taken and the time is released after 15 minutes.</p>
  @endif
  <p><a href="/">Back to the website</a></p>
</main>
</body>
</html>
