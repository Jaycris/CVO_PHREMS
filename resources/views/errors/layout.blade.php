<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>@yield('title') | {{ config('app.name') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-mark.png') }}">

        @vite(['resources/css/app.css'])
    </head>
    <body class="h-full font-sans antialiased">
        <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-ink-950 px-4 py-10">
            <div class="absolute inset-0"
                 style="background: radial-gradient(ellipse 52% 48% at 12% 8%, rgba(21,122,82,0.42), transparent 62%), radial-gradient(ellipse 46% 42% at 88% 28%, rgba(37,99,235,0.18), transparent 62%), linear-gradient(135deg, #020617 0%, #0f172a 52%, #052e23 100%);">
            </div>

            <img src="{{ asset('images/logo-mark.png') }}" alt=""
                 class="pointer-events-none absolute -bottom-20 -left-16 h-[28rem] w-[28rem] object-contain opacity-[0.08]">

            <div class="relative z-10 w-full max-w-lg overflow-hidden rounded-lg border border-white/10 bg-white shadow-2xl shadow-black/30 dark:bg-ink-900">
                <div class="p-8 sm:p-10">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-700 dark:text-brand-400">
                        Error @yield('code')
                    </p>

                    <h1 class="mt-3 text-2xl font-bold text-ink-950 dark:text-white">@yield('title')</h1>

                    <p class="mt-3 text-sm font-medium leading-6 text-ink-500 dark:text-ink-400">
                        @yield('message')
                    </p>

                    @hasSection('detail')
                        <div class="mt-4 rounded-lg bg-[#f8fafc] p-3 text-sm font-medium text-ink-600 dark:bg-ink-800/60 dark:text-ink-300">
                            @yield('detail')
                        </div>
                    @endif

                    <div class="mt-7 flex flex-wrap gap-2">
                        @hasSection('actions')
                            @yield('actions')
                        @else
                            <a href="{{ url('/dashboard') }}"
                               class="inline-flex h-11 items-center rounded-lg bg-brand-700 px-5 text-sm font-bold text-white shadow-lg shadow-brand-900/20 transition hover:bg-brand-800">
                                Back to Dashboard
                            </a>
                        @endif
                    </div>
                </div>

                <div class="border-t border-ink-200/80 bg-[#f8fafc] px-8 py-4 dark:border-white/10 dark:bg-ink-900/60 sm:px-10">
                    <p class="text-xs font-medium text-ink-500 dark:text-ink-500">
                        {{ config('app.name') }} · CreatiVision Outsourcing
                    </p>
                </div>
            </div>
        </div>
    </body>
</html>
