<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('How was it?') }} · {{ $business }}</title>
@include('reviews::public._style')
</head>
<body>
<main>
  <p>{{ $business }}</p>
  @if ($answered)
    <h1>{{ __('Thank you') }}</h1>
    <p>{{ __('You’ve already told us how it was.') }}</p>
    @if ($google)<a class="button" href="{{ $google }}" rel="noopener">{{ __('Review us on Google too') }}</a>@endif
  @else
    <h1>{{ __('How was your :what?', ['what' => $title]) }}</h1>
    @if ($problem)<p class="problem" role="alert">{{ $problem }}</p>@endif
    <form method="post" action="{{ $action }}">
      <fieldset>
        <legend>{{ __('Your rating') }}</legend>
        <div class="stars">
          @for ($i = 1; $i <= 5; $i++)
            <label><input type="radio" name="rating" value="{{ $i }}" required><span>{{ $i }} ★</span></label>
          @endfor
        </div>
      </fieldset>
      <label class="block" for="comment">{{ __('Tell others about it (optional)') }}</label>
      <textarea id="comment" name="comment" maxlength="1000"></textarea>
      <p class="hint">{{ __('Shown on :business’s website with your first name and initial.', ['business' => $business]) }}</p>
      <label class="block" for="private_note">{{ __('Anything we should put right? (optional)') }}</label>
      <textarea id="private_note" name="private_note" maxlength="1000"></textarea>
      <p class="hint">{{ __('Only the owner sees this.') }}</p>
      <button type="submit">{{ __('Send') }}</button>
    </form>
  @endif
</main>
</body>
</html>
