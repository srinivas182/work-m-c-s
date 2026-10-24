<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
@if ($description)
<meta name="description" content="{{ $description }}">
@endif
@foreach ($preloads as $font)
<link rel="preload" href="{{ $font }}" as="font" type="font/woff2" crossorigin>
@endforeach
@vite('resources/css/public-site.css')
</head>
<body style="margin:0">
{!! $body !!}
</body>
</html>
