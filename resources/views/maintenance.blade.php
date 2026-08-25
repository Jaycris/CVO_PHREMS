<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">

        <title>System Maintenance | PHREMS</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-mark.png') }}">
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-full bg-[#edf3f7] font-sans antialiased text-ink-950">
        <main class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-8 sm:px-6 lg:px-8">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-2 bg-brand-800"></div>

            <div class="relative w-full max-w-5xl overflow-hidden rounded-lg border border-ink-200 bg-white shadow-xl shadow-ink-900/10">
                <div class="grid lg:grid-cols-[0.82fr_1.18fr]">
                    <section class="relative flex min-h-72 flex-col justify-between overflow-hidden bg-ink-950 p-7 text-white sm:p-10 lg:min-h-[34rem]">
                        <div class="pointer-events-none absolute -bottom-20 -left-20 h-72 w-72 opacity-[0.06]">
                            <img src="{{ asset('images/logo-mark.png') }}" alt="" class="h-full w-full object-contain">
                        </div>

                        <div class="relative">
                            <div class="inline-flex rounded-md bg-white px-4 py-3">
                                <img src="{{ asset('images/logo.png') }}" alt="CreatiVision Outsourcing" class="h-12 w-auto object-contain sm:h-14">
                            </div>

                            <p class="mt-9 text-xs font-bold uppercase tracking-[0.2em] text-brand-300">Payroll, HR, and Employee Management System</p>
                            <h1 class="mt-3 text-4xl font-bold leading-tight sm:text-5xl">PHREMS</h1>
                            <p class="mt-4 max-w-sm text-base font-medium leading-7 text-ink-300">
                                A secure workspace for CreatiVision Outsourcing employees and HR operations.
                            </p>
                        </div>

                        <div class="relative mt-10 border-t border-white/10 pt-5">
                            <p class="text-sm font-semibold text-ink-200">CreatiVision Outsourcing</p>
                            <p class="mt-1 text-xs font-medium text-ink-400">Human Resources and Employee Services</p>
                        </div>
                    </section>

                    <section class="flex flex-col justify-center p-7 sm:p-10 lg:p-14">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-brand-200 bg-brand-50 text-brand-700" aria-hidden="true">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2.25" />
                                <circle cx="12" cy="12" r="9" />
                            </svg>
                        </div>

                        <p class="mt-6 text-xs font-bold uppercase tracking-[0.18em] text-brand-700">Scheduled Maintenance</p>
                        <h2 class="mt-3 text-3xl font-bold leading-tight text-ink-950 sm:text-4xl">PHREMS is temporarily unavailable</h2>
                        <p class="mt-4 text-base font-medium leading-7 text-ink-600">
                            We are performing system maintenance to keep PHREMS reliable and secure. Please try again shortly.
                        </p>

                        <div class="mt-7 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg border border-ink-200 bg-ink-50 p-4">
                                <div class="flex items-center gap-3">
                                    <svg class="h-5 w-5 shrink-0 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M12 3.75l7.5 3v5.5c0 4.5-3.1 7.1-7.5 8-4.4-.9-7.5-3.5-7.5-8v-5.5l7.5-3Z" />
                                    </svg>
                                    <p class="text-sm font-bold text-ink-900">Your records are safe</p>
                                </div>
                                <p class="mt-2 text-sm font-medium leading-6 text-ink-500">Saved employee, attendance, and payroll information is not affected.</p>
                            </div>

                            <div class="rounded-lg border border-ink-200 bg-ink-50 p-4">
                                <div class="flex items-center gap-3">
                                    <svg class="h-5 w-5 shrink-0 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75v5.5m0 3.25h.01M10.3 3.9 2.7 17.1A1.5 1.5 0 0 0 4 19.35h16a1.5 1.5 0 0 0 1.3-2.25L13.7 3.9a1.96 1.96 0 0 0-3.4 0Z" />
                                    </svg>
                                    <p class="text-sm font-bold text-ink-900">Need urgent assistance?</p>
                                </div>
                                <p class="mt-2 text-sm font-medium leading-6 text-ink-500">Please contact Human Resources or your system administrator.</p>
                            </div>
                        </div>

                        <div class="mt-8 flex flex-wrap items-center gap-4">
                            <button type="button" onclick="this.disabled = true; this.querySelector('[data-label]').textContent = 'Refreshing...'; window.location.reload();"
                                class="inline-flex h-11 items-center gap-2 rounded-lg bg-brand-700 px-5 text-sm font-bold text-white shadow-md shadow-brand-900/15 transition hover:bg-brand-800 disabled:cursor-wait disabled:opacity-75">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 11a8.1 8.1 0 1 0-2.4 5.8M20 5v6h-6" />
                                </svg>
                                <span data-label>Refresh Page</span>
                            </button>
                            <p class="text-sm font-medium text-ink-500">Thank you for your patience.</p>
                        </div>
                    </section>
                </div>
            </div>

            <p class="absolute inset-x-4 bottom-3 text-center text-xs font-medium text-ink-500">
                Copyright {{ now()->year }} | CreatiVision Outsourcing Team
            </p>
        </main>
    </body>
</html>
