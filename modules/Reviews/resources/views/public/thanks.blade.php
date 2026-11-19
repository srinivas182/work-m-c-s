<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('Thank you') }} · {{ $business }}</title>
@include('reviews::public._style')
</head>
<body>
<main>
  <p>{{ $business }}</p>
  <h1>{{ __('Thank you') }}</h1>
  <p>{{ __('Your review helps :business, and the people choosing where to go.', ['business' => $business]) }}</p>
  @if ($privately)<p>{{ __('The owner will read what you said privately.') }}</p>@endif
  {{-- The same for every rating: no gating (Google's rules; the FTC's review rule). --}}
  @if ($google)
    <p>{{ __('Would you also share it on Google? It helps new customers find them.') }}</p>
    <a class="button" href="{{ $google }}" rel="noopener">{{ __('Review us on Google') }}</a>
  @endif
</main>
</body>
</html>
