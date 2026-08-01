<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>
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
    <body class="h-full font-sans antialiased">
        <div class="app-surface flex h-full min-h-screen" x-data="{ sidebarOpen: false }">
            {{-- Sidebar --}}
            <aside class="fixed inset-y-0 left-0 z-30 flex w-[17rem] -translate-x-full transform flex-col border-r border-ink-200/80 bg-white/95 shadow-xl shadow-ink-200/40 backdrop-blur transition-transform duration-200 ease-in-out lg:static lg:translate-x-0 lg:shadow-none dark:border-white/10 dark:bg-ink-950/95 dark:shadow-black/30"
                   :class="{ 'translate-x-0': sidebarOpen }">
                <div class="border-b border-ink-200/80 px-4 py-5 dark:border-white/10">
                    <img src="{{ asset('images/logo.png') }}" alt="CreatiVision" class="h-auto w-full object-contain">
                </div>

                <div class="px-4 pt-4">
                    <p class="px-1 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-400">Human Resources</p>
                </div>

                <nav class="mt-2 flex-1 space-y-1 overflow-y-auto px-2.5 pb-6">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="home">Dashboard</x-nav-link>
                    <x-nav-link :href="route('attendance.punch')" :active="request()->routeIs('attendance.punch')" icon="clock">My Attendance</x-nav-link>
                    <x-nav-link :href="route('leave-requests.index')" :active="request()->routeIs('leave-requests.*')" icon="clipboard">Leave Requests</x-nav-link>

                    @hasanyrole('Admin|HR')
                        <p class="mb-1 mt-6 px-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-500">Organization</p>
                        <x-nav-link :href="route('org.departments')" :active="request()->routeIs('org.departments')" icon="building">Departments</x-nav-link>
                        <x-nav-link :href="route('org.positions')" :active="request()->routeIs('org.positions')" icon="tag">Positions</x-nav-link>

                        <p class="mb-1 mt-6 px-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-500">People</p>
                        <x-nav-link :href="route('employees.index')" :active="request()->routeIs('employees.*')" icon="people-group">Employees</x-nav-link>
                        <x-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')" icon="users">Users</x-nav-link>
                        <x-nav-link :href="route('schedules.index')" :active="request()->routeIs('schedules.*')" icon="calendar">Work Schedules</x-nav-link>
                        <x-nav-link :href="route('attendance.dtr')" :active="request()->routeIs('attendance.dtr')" icon="clock">DTR</x-nav-link>
                        <x-nav-link :href="route('leave-types.index')" :active="request()->routeIs('leave-types.*')" icon="document">Leave Types</x-nav-link>

                        <p class="mb-1 mt-6 px-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-500">Reports</p>
                        <x-nav-link :href="route('reports.attendance-summary')" :active="request()->routeIs('reports.attendance-summary')" icon="chart">Attendance Summary</x-nav-link>
                    @endhasanyrole
                </nav>
            </aside>

            <div class="fixed inset-0 z-20 bg-ink-950/40 backdrop-blur-sm lg:hidden" x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"></div>

            {{-- Main --}}
            <div class="flex min-h-screen flex-1 flex-col lg:pl-0">
                @php
                    $pageTitle = match (true) {
                        request()->routeIs('dashboard') => 'Dashboard',
                        request()->routeIs('attendance.punch') => 'My Attendance',
                        request()->routeIs('leave-requests.*') => 'Leave Requests',
                        request()->routeIs('org.departments') => 'Departments',
                        request()->routeIs('org.positions') => 'Positions',
                        request()->routeIs('employees.*') => 'Employees',
                        request()->routeIs('users.*') => 'Users',
                        request()->routeIs('schedules.*') => 'Work Schedules',
                        request()->routeIs('attendance.dtr') => 'DTR',
                        request()->routeIs('leave-types.*') => 'Leave Types',
                        request()->routeIs('reports.attendance-summary') => 'Attendance Summary',
                        default => $title ?? '',
                    };
                @endphp
                <header class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-ink-200/80 bg-white/85 px-4 shadow-sm shadow-ink-200/50 backdrop-blur-xl sm:px-6 dark:border-white/10 dark:bg-ink-950/80 dark:shadow-black/20">
                    <div class="flex items-center gap-3">
                        <button @click="sidebarOpen = true" class="rounded-lg p-2 font-medium text-ink-500 hover:bg-ink-100 lg:hidden dark:hover:bg-white/10">
                            <x-icon name="chevron-down" class="h-5 w-5 rotate-90" />
                        </button>
                        <div>
                            <p class="hidden text-xs font-semibold uppercase tracking-[0.16em] text-brand-700 dark:text-brand-300 sm:block">CreatiVision HRIS</p>
                            @unless (request()->routeIs('org.departments') || request()->routeIs('org.positions') || request()->routeIs('employees.*'))
                                <h1 class="text-xl font-bold text-ink-950 dark:text-white">{{ $pageTitle }}</h1>
                            @endunless
                        </div>
                    </div>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <x-theme-toggle />
                        <livewire:notifications.bell />

                        <div class="relative" x-data @click.outside="
                                $refs.userMenuPanel.classList.add('opacity-0', 'scale-95', 'pointer-events-none');
                                $refs.userMenuPanel.classList.remove('opacity-100', 'scale-100');
                            ">
                            <button @click="
                                    const willOpen = $refs.userMenuPanel.classList.contains('opacity-0');
                                    $refs.userMenuPanel.classList.toggle('opacity-0', !willOpen);
                                    $refs.userMenuPanel.classList.toggle('scale-95', !willOpen);
                                    $refs.userMenuPanel.classList.toggle('pointer-events-none', !willOpen);
                                    $refs.userMenuPanel.classList.toggle('opacity-100', willOpen);
                                    $refs.userMenuPanel.classList.toggle('scale-100', willOpen);
                                " class="flex items-center gap-2 rounded-full border border-transparent py-1 pl-1 pr-2 transition hover:border-ink-200 hover:bg-white hover:shadow-sm dark:hover:border-white/10 dark:hover:bg-white/10">
                                <img src="{{ asset('images/logo-mark.png') }}" alt="" class="h-9 w-9 rounded-full border border-ink-200 bg-white object-contain p-1 dark:border-white/10">
                                <div class="hidden text-left text-sm leading-tight sm:block">
                                    <p class="font-bold text-ink-950 dark:text-white">{{ config('app.name') }}</p>
                                    <p class="text-xs font-medium text-ink-500 dark:text-ink-400">{{ auth()->user()->getRoleNames()->join(', ') ?: 'No role' }}</p>
                                </div>
                                <x-icon name="chevron-down" class="hidden h-4 w-4 font-medium text-ink-500 sm:block" />
                            </button>

                            <div x-ref="userMenuPanel"
                                 class="pointer-events-none absolute right-0 z-20 mt-2 w-64 origin-top-right scale-95 rounded-lg border border-ink-200 bg-white p-2 opacity-0 shadow-xl shadow-ink-200/50 transition duration-150 ease-out dark:border-white/10 dark:bg-ink-900 dark:shadow-black/30">
                                <div class="flex items-center gap-3 rounded-lg p-2">
                                    <img src="{{ asset('images/logo-mark.png') }}" alt="" class="h-10 w-10 rounded-full border border-ink-200 bg-white object-contain p-1.5 dark:border-white/10">
                                    <div class="min-w-0 text-sm leading-tight">
                                        <p class="truncate font-bold text-ink-950 dark:text-white">{{ auth()->user()->name }}</p>
                                        <p class="truncate text-xs font-medium text-ink-500 dark:text-ink-400">{{ auth()->user()->email }}</p>
                                    </div>
                                </div>
                                <div class="my-2 h-px bg-ink-100 dark:bg-white/10"></div>
                                <livewire:auth.logout-button />
                            </div>
                        </div>
                    </div>
                </header>

                <main class="flex-1 p-4 sm:p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
