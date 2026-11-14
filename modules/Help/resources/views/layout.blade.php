<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Help' }} — WURK Help</title>
<meta name="description" content="{{ $description ?? 'Answers, guides and 30-second videos for running your business on WURK.' }}">
<style>
:root { color-scheme: light dark; --fg: #111; --muted: #555; --bg: #fff; --line: #e5e5e5; --accent: #0f766e; }
@media (prefers-color-scheme: dark) { :root { --fg: #f2f2f2; --muted: #b5b5b5; --bg: #121212; --line: #333; --accent: #5eead4; } }
body { margin: 0; font: 16px/1.6 system-ui, sans-serif; color: var(--fg); background: var(--bg); }
.wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 64px; }
a { color: var(--accent); }
header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
form.search { display: flex; gap: 8px; margin: 16px 0 24px; }
form.search input { flex: 1; padding: 10px 12px; font: inherit; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--fg); }
button { padding: 10px 14px; font: inherit; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--fg); cursor: pointer; }
ul.cards { list-style: none; padding: 0; display: grid; gap: 12px; }
ul.cards li { border: 1px solid var(--line); border-radius: 10px; padding: 14px; }
.muted { color: var(--muted); }
video { width: 100%; border-radius: 10px; margin: 12px 0; }
article img { max-width: 100%; }
</style>
</head>
<body>
<div class="wrap">
<header><a href="/"><strong>WURK Help</strong></a><a href="https://{{ config('wurk.domains.app') }}">Go to WURK</a></header>
<form class="search" action="/search" method="get" role="search">
<label for="q" class="muted" style="position:absolute;left:-9999px">Search help</label>
<input id="q" name="q" type="search" value="{{ $q ?? '' }}" placeholder="Search help, e.g. “add a branch”">
<button type="submit">Search</button>
</form>
<main>@yield('main')</main>
</div>
</body>
</html>
