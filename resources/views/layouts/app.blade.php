<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Commerce') }}</title>

    {{-- Prevent dark-mode flash before Alpine loads --}}
    <script>
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>

    {{-- Inter font --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-white"
      x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }"
      x-init="$watch('darkMode', val => {
          document.documentElement.classList.toggle('dark', val);
          localStorage.setItem('darkMode', val);
      })">

    {{-- Skip to main content (a11y) --}}
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[9999]
              focus:px-4 focus:py-2 focus:bg-blue-600 focus:text-white focus:rounded-lg focus:text-sm focus:font-medium">
        Skip to main content
    </a>

    <div class="flex h-screen overflow-hidden">
        @include('layouts.app.sidebar')

        <div class="flex-1 flex flex-col overflow-hidden pl-64">
            @include('layouts.app.header')

            <main id="main-content"
                  class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-950"
                  tabindex="-1">
                <div class="p-8">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
