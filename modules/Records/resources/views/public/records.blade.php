<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Your documents · {{ $business }}</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 34rem; margin: 0 auto; padding: 2rem 1.25rem; display: grid; gap: 1.1rem; }
  h1 { font-size: 1.5rem; margin: 0; } p { color: #4b4766; line-height: 1.5; margin: 0; }
  .card { background: #fff; border: 1px solid #e3e1ee; border-radius: 12px; padding: 1rem 1.25rem; display: grid; gap: .7rem; }
  label { font-weight: 600; } input { font: inherit; font-size: 1.25rem; letter-spacing: .3em; padding: .6rem .8rem; border: 1px solid #b9b5cc; border-radius: 8px; width: 9rem; }
  button { font: inherit; padding: .65rem 1.1rem; border: 0; border-radius: 8px; background: #1b1640; color: #fff; font-weight: 600; cursor: pointer; justify-self: start; }
  ul { list-style: none; padding: 0; margin: 0; display: grid; gap: .6rem; } li { display: grid; gap: .2rem; }
  a { color: #1b1640; font-weight: 600; } .muted { font-size: .9rem; } [role=alert] { color: #9b1c1c; }
</style>
</head>
<body>
<main>
  <p>{{ $business }}</p>
  @if ($state === 'expired')
    <h1>This link has expired</h1>
    <p>Please ask {{ $business }} to send you a new one.</p>
  @elseif ($state === 'code')
    <h1>Your documents</h1>
    <p>To keep them private, we’ll send a one-time code to the email or mobile number {{ $business }} has for you.</p>
    @if ($error === 'wrong')<p role="alert">That code didn’t match or has expired. Try again, or ask for a new code.</p>@endif
    @if ($error === 'locked')<p role="alert">Too many tries — this link is locked. Please ask {{ $business }} to send you a new one.</p>@endif
    <form class="card" method="post" action="/records/{{ $token }}/code" aria-label="Send me a code">
      <p>@if ($sent) We’ve sent you a code. It expires in 10 minutes. @else First, ask for a code. @endif</p>
      <button type="submit">{{ $sent ? 'Send a new code' : 'Send me a code' }}</button>
    </form>
    @if ($sent)
    <form class="card" method="post" action="/records/{{ $token }}/verify" aria-label="Enter your code">
      <label for="code">Your 6-digit code</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
      <button type="submit">Open my documents</button>
    </form>
    @endif
  @else
    <h1>Your documents</h1>
    <p>Hello {{ $patient }}. For your privacy, this page closes after 30 minutes.</p>
    <section class="card" aria-label="Documents">
      @if (count($docs) === 0)
        <p>There are no documents yet.</p>
      @else
      <ul>
        @foreach ($docs as $d)
        <li>
          <strong>{{ $d['title'] }}</strong>
          <span class="muted">{{ $d['date'] }}@if ($d['version'] > 1) · version {{ $d['version'] }}@endif @if (! $d['latest']) · an earlier version @endif @if ($d['note']) · {{ $d['note'] }}@endif</span>
          <span><a href="/records/{{ $token }}/{{ $d['id'] }}">Open</a> · <a href="/records/{{ $token }}/{{ $d['id'] }}?download=1">Download</a></span>
        </li>
        @endforeach
      </ul>
      @endif
    </section>
  @endif
</main>
</body>
</html>
