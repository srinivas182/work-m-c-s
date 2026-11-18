<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"@if (\Wurk\Platform\I18n\Lite::on(request())) data-lite="1"@endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">
        <link rel="manifest" href="/manifest.webmanifest">
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            {{-- Apply the saved or system theme before first paint to avoid a flash. --}}
            (function () {
                try {
                    var saved = localStorage.getItem('wurk-theme');
                    var dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                } catch (e) {}
            })();
        </script>
        @if (app()->getLocale() !== 'en')
            {{-- This language's translations (24.1), before the app so the first paint is already translated. --}}
            <script src="/i18n/{{ app()->getLocale() }}.{{ \Wurk\Platform\I18n\Locales::version(app()->getLocale()) }}.js" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
        @endif
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        {{-- A title from the first byte; each page's own title replaces it (Inertia <Head>). --}}
        <title inertia>{{ config('app.name', 'WURK') }}</title>
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
