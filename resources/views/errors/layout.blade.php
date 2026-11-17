{{--
    WURK's error pages (Sprint 18.6a): plain words, what to do next, light and dark, readable on any phone.
    Self-contained (no built assets) so they still show when something else is broken. On a business's own
    website they stay neutral: no WURK marketing, just a way back to its home page.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        :root { color-scheme: light dark; --bg: #f7f7fb; --card: #ffffff; --fg: #1b1640; --muted: #4a4560; --brand: #5b3be8; --border: #e6e3f0; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0e0e0f; --card: #18171c; --fg: #f4f3f8; --muted: #b9b5c9; --brand: #b3a1ff; --border: #2e2c36; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--fg);
            font: 17px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 32rem; background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 32px 28px; }
        .code { margin: 0; font-size: 14px; font-weight: 600; letter-spacing: .04em; color: var(--muted); }
        h1 { margin: 8px 0 12px; font-size: 26px; line-height: 1.25; }
        p { margin: 0 0 12px; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
        a.button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 12px; font-weight: 600; text-decoration: none; }
        a.primary { background: var(--brand); color: #fff; }
        @media (prefers-color-scheme: dark) { a.primary { color: #12082f; } }
        a.secondary { border: 1px solid var(--border); color: var(--fg); }
        a:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
    </style>
</head>
<body>
    <main>
        <p class="code">@yield('code')</p>
        <h1>@yield('heading')</h1>
        @yield('message')
        <div class="actions">
            @section('actions')
                <a class="button primary" href="/">Go to the home page</a>
            @show
        </div>
    </main>
</body>
</html>
