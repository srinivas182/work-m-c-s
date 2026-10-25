<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Your booking · {{ $business }}</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 34rem; margin: 0 auto; padding: 2rem 1.25rem; display: grid; gap: 1.25rem; }
  h1 { font-size: 1.6rem; margin: 0; } h2 { font-size: 1.15rem; margin: 0 0 .5rem; }
  p { color: #4b4766; line-height: 1.5; margin: 0; }
  .card { background: #fff; border: 1px solid #e3e1ee; border-radius: 12px; padding: 1rem 1.25rem; display: grid; gap: .6rem; }
  .notice { background: #eef3ff; border-color: #c9d6ff; color: #1b1640; }
  button, input { font: inherit; } input[type=date] { padding: .55rem .75rem; border: 1px solid #c9c6da; border-radius: 8px; }
  button { padding: .6rem 1rem; border-radius: 8px; border: 1px solid #1b1640; background: #1b1640; color: #fff; font-weight: 600; cursor: pointer; }
  button.secondary { background: #fff; color: #1b1640; }
  .times { display: flex; flex-wrap: wrap; gap: .5rem; border: 0; padding: 0; margin: 0; }
  .times label { border: 1px solid #c9c6da; border-radius: 8px; padding: .45rem .7rem; }
</style>
</head>
<body>
<main>
  <p>{{ $business }}</p>
  <h1>Your booking</h1>
  @if ($notice)
  <p class="card notice" role="status">{{ $notice }}</p>
  @endif
  <section class="card" aria-label="Booking details">
    <p><strong>{{ $service }}</strong> for {{ $customer }}</p>
    <p>{{ $when }}</p>
    @if (! $changeable)
    <p>{{ ['cancelled' => 'This booking is cancelled.', 'expired' => 'This booking wasn’t completed: the deposit wasn’t paid in time.', 'no_show' => 'This booking was missed.', 'completed' => 'This booking is done.'][$status] ?? 'This booking can’t be changed online any more. Please contact us.' }}</p>
    @endif
  </section>

  @if ($changeable)
  <section class="card" aria-label="Cancellation policy">
    <h2>Cancellation policy</h2>
    <p>{{ $policy }}</p>
    @if ($deposit === 'refund')
    <p><strong>If you cancel now, your deposit will be refunded.</strong></p>
    @elseif ($deposit === 'keep')
    <p><strong>If you cancel now, the deposit is kept.</strong></p>
    @endif
  </section>

  <section class="card" aria-label="Move to another time">
    <h2>Move to another time</h2>
    <form method="get" action="{{ $action }}">
      @foreach ($signature as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
      <label for="date">Day</label>
      <input id="date" type="date" name="date" value="{{ $date }}" required>
      <button type="submit" class="secondary">Show free times</button>
    </form>
    @if ($date !== null)
      @if (count($times) === 0)
      <p>No free times on this day. Try another day.</p>
      @else
      <form method="post" action="{{ $moveUrl }}">
        <fieldset class="times"><legend>Free times</legend>
          @foreach ($times as $t)
          <label><input type="radio" name="starts_at" value="{{ $t['starts_at'] }}" required> {{ $t['label'] }}</label>
          @endforeach
        </fieldset>
        <p><button type="submit">Move my booking</button></p>
      </form>
      @endif
    @endif
  </section>

  <section class="card" aria-label="Cancel">
    <h2>Cancel</h2>
    <form method="post" action="{{ $cancelUrl }}">
      <button type="submit" class="secondary">Cancel my booking</button>
    </form>
  </section>
  @endif
  <p><a href="/">Back to the website</a></p>
</main>
</body>
</html>
