{{-- The React admin (resources/js/admin), beside the Filament panels. Its CSS is its own file: nothing here reaches them. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="color-scheme" content="light dark" />
    <meta name="robots" content="noindex" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    {{-- Light or dark before anything paints: Filament's own "theme" choice, else the device's --}}
    <script>
        (function () {
            var theme = 'system';
            try {
                theme = localStorage.getItem('theme') || 'system';
            } catch (error) {}
            var dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    <title>{{ $title }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" />

    <script>
        window.ADMIN826 = @json($console);
    </script>

    @viteReactRefresh
    @vite(['resources/css/console.css', 'resources/js/admin/main.tsx'])
</head>

<body>
    <div id="console"></div>
    <noscript><p style="padding: 1rem">The admin needs JavaScript. <a href="{{ url($console['panel']) }}">Open the current admin</a>.</p></noscript>
</body>
</html>
