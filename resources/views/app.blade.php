<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700&display=swap" rel="stylesheet">

    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    {{-- Apply the saved theme before paint to avoid a flash of the wrong mode. --}}
    <script>
        (function () {
            try {
                var p = localStorage.getItem('theme') || 'system';
                var dark = p === 'dark' || (p === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased bg-gray-50 dark:bg-gray-900">
    @inertia
</body>
</html>
