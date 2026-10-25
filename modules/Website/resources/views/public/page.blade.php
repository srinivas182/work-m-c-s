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
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:url" content="{{ $canonical }}">
@if ($description)
<meta property="og:description" content="{{ $description }}">
@endif
@if ($image)
<meta property="og:image" content="{{ $image }}">
<meta name="twitter:card" content="summary_large_image">
@endif
@if ($favicon)
<link rel="icon" href="{{ $favicon }}">
@endif
@if ($jsonLd)
<script type="application/ld+json">{!! $jsonLd !!}</script>
@endif
<link rel="stylesheet" href="{{ $stylesheet }}">
@if ($islands)
<script type="module" src="{{ $islands }}"></script>
@endif
</head>
<body style="margin:0">
{!! $body !!}
</body>
</html>
