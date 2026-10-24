<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">
        <script>
            {{-- Apply the saved or system theme before first paint to avoid a flash. --}}
            (function () {
                try {
                    var saved = localStorage.getItem('wurk-theme');
                    var dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                } catch (e) {}
            })();
        </script>
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
