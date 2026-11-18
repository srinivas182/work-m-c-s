{{-- The customer's language on a customer page (24.3): links, so it works without a script and on any phone. --}}
<nav aria-label="{{ __('Language') }}" style="margin-top:1.5rem;font-size:.875rem">
  @foreach (\Wurk\Platform\I18n\Locales::offered() as $l)
    @if ($l['code'] === app()->getLocale())
      <strong lang="{{ $l['code'] }}">{{ $l['name'] }}</strong>
    @else
      <a href="?{{ http_build_query([...request()->except('lang'), 'lang' => $l['code']]) }}" lang="{{ $l['code'] }}" hreflang="{{ $l['code'] }}">{{ $l['name'] }}</a>
    @endif
    @if (! $loop->last) · @endif
  @endforeach
</nav>
