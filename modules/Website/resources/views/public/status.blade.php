<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $heading }} · {{ $business }}</title>
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
  <h1>{{ $heading }}</h1>
  <p>{{ $message }}</p>
  @if ($home)
  <p><a href="/">Go to the home page</a></p>
  @endif
</main>
</body>
</html>
