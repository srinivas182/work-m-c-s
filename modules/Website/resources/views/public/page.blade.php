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
@if ($whatsapp)
{{-- WhatsApp's darker green: white on it meets the 3:1 contrast graphics need (the bright green doesn't). --}}
<a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Chat on WhatsApp" title="Chat on WhatsApp"
   style="position:fixed;right:16px;bottom:max(16px,env(safe-area-inset-bottom));z-index:50;display:grid;place-items:center;width:56px;height:56px;border-radius:9999px;background:#128C7E;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.25)">
<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2 0-.1-.2-.2-.4-.3Z"/></svg>
</a>
@endif
</body>
</html>
