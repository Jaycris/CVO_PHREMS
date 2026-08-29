<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        {{-- Belt and braces with the X-Robots-Tag header. This is the page a
             search engine can actually reach, so it is the one that matters. --}}
        <meta name="robots" content="noindex, nofollow, noarchive">

        <title>PHREMS | CreatiVision Outsourcing</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-mark.png') }}">

        <script>
            (function () {
                function applyTheme() {
                    const isDark = localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', isDark);
                }
                applyTheme();
                document.addEventListener('livewire:navigated', applyTheme);
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="h-full bg-ink-50 font-sans antialiased dark:bg-ink-950">
        {{ $slot }}


        @livewireScripts
    </body>
</html>
