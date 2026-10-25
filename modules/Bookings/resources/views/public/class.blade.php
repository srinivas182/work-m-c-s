<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Your class · {{ $business }}</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 34rem; margin: 0 auto; padding: 2rem 1.25rem; display: grid; gap: 1.25rem; }
  h1 { font-size: 1.6rem; margin: 0; } p { color: #4b4766; line-height: 1.5; margin: 0; }
  .card { background: #fff; border: 1px solid #e3e1ee; border-radius: 12px; padding: 1rem 1.25rem; display: grid; gap: .6rem; }
  button { font: inherit; padding: .6rem 1rem; border-radius: 8px; border: 1px solid #1b1640; background: #fff; color: #1b1640; font-weight: 600; cursor: pointer; }
  a { color: #1b1640; font-weight: 600; }
</style>
</head>
<body>
<main>
  <p>{{ $business }}</p>
  <h1>Your class</h1>
  @if ($notice)
  <p class="card" role="status">{{ $notice }}</p>
  @endif
  <section class="card" aria-label="Class details">
    <p><strong>{{ $title }}</strong> — {{ $customer }}</p>
    <p>{{ $when }}</p>
    @if ($online)
      @if ($joinUrl)
      <p><a href="{{ $joinUrl }}" rel="noopener noreferrer"><strong>Join online now</strong></a></p>
      @else
      <p>This is an online class. Your joining link appears here 15 minutes before it starts.</p>
      @endif
    @endif
    @if (! $changeable)
    <p>{{ ['cancelled' => 'Your place is cancelled.', 'class_cancelled' => 'This class has been cancelled by the business.', 'attended' => 'Thanks for coming!', 'no_show' => 'This class was missed.'][$status] ?? 'This place can’t be changed online any more. Please contact us.' }}</p>
    @endif
  </section>
  @if ($changeable)
  <form class="card" method="post" action="{{ $cancelUrl }}" aria-label="Cancel">
    <p>Can’t make it? Let us know so someone else can have your place.</p>
    <p><button type="submit">Cancel my place</button></p>
  </form>
  @endif
  <p><a href="/">Back to the website</a></p>
</main>
</body>
</html>
