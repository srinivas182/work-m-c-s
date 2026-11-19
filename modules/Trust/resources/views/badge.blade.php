<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $badge['verified'] ? __('Verified by WURK') : __('Not verified') }} · {{ $business }}</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 36rem; margin: 0 auto; padding: 2.5rem 1rem; }
  h1 { font-size: 1.6rem; margin: .25rem 0 1rem; }
  p, li { color: #4b4766; line-height: 1.55; }
  ul { padding-left: 1.2rem; }
  .ok { color: #14532d; font-weight: 700; }
  .no { color: #4b4766; font-weight: 700; }
</style>
</head>
<body>
<main>
  <p>WURK</p>
  @if ($badge['verified'])
    <h1>{{ __(':business is verified by WURK', ['business' => $business]) }}</h1>
    <ul>
      <li><span class="ok">✓</span> {{ __('The owner’s mobile number was confirmed with a one-time code.') }}</li>
      <li><span class="ok">✓</span> {{ __('WURK checked the owner’s identity document against a photo of them, on :date.', ['date' => \Carbon\Carbon::parse((string) $badge['checked_on'])->format('j F Y')]) }}</li>
      @if ($badge['registered'])
        <li><span class="ok">✓</span> {{ __('WURK matched the business’s registration number with the official company register.') }}</li>
      @endif
    </ul>
    <p>{{ __('Identity checks are redone every two years. WURK doesn’t keep the documents: they’re deleted after the check.') }}</p>
    <p>{{ __('Verified means WURK knows who runs this business. It isn’t a promise about the quality of their work — read their reviews for that.') }}</p>
  @else
    <h1>{{ __(':business isn’t verified by WURK', ['business' => $business]) }}</h1>
    <p class="no">{{ __('This business hasn’t completed WURK’s checks, or its check has lapsed.') }}</p>
  @endif
</main>
</body>
</html>
