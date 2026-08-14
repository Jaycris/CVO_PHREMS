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
        <div class="app-surface flex min-h-screen" x-data="{ sidebarOpen: false }">
            {{-- Sidebar --}}
            <aside class="fixed inset-y-0 left-0 z-30 flex w-[17rem] -translate-x-full transform flex-col border-r border-ink-200/80 bg-white/95 shadow-xl shadow-ink-200/40 backdrop-blur transition-transform duration-200 ease-in-out lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:shadow-none dark:border-white/10 dark:bg-ink-950/95 dark:shadow-black/30"
                   :class="{ 'translate-x-0': sidebarOpen }">
                <div class="border-b border-ink-200/80 px-4 py-5 dark:border-white/10">
                    <img src="{{ asset('images/logo.png') }}" alt="CreatiVision" class="h-auto w-full object-contain">
                </div>

                @php
                    /*
                     * The menu is the permission set made visible: a section
                     * appears only when the signer holds something inside it, so
                     * an employee sees a short self-service menu and an HR user
                     * sees HR work — without either being told what they cannot
                     * reach.
                     */
                    $showOrganization = auth()->user()->canAny(['org.departments.manage', 'org.positions.manage']);
                    $showPeople = auth()->user()->canAny([
                        'employees.manage', 'users.manage', 'schedules.manage',
                        'attendance.view_all', 'leave.types.manage', 'cash_advances.manage',
                        'recruitment.manage',
                        'reimbursements.view_all',
                    ]);
                @endphp

                <div class="px-4 pt-4">
                    <p class="px-1 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-400">Human Resources</p>
                </div>

                <nav class="mt-2 flex-1 space-y-1 overflow-y-auto px-2.5 pb-6">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="home">Dashboard</x-nav-link>
                    <x-nav-link :href="route('my-profile')" :active="request()->routeIs('my-profile')" icon="user-circle">My Profile</x-nav-link>
                    <x-nav-link :href="route('attendance.punch')" :active="request()->routeIs('attendance.punch')" icon="clock">My Attendance</x-nav-link>
                    <x-nav-link :href="route('my-payslips')" :active="request()->routeIs('my-payslips*')" icon="money">My Payslips</x-nav-link>
                    <x-nav-link :href="route('leave-requests.index')" :active="request()->routeIs('leave-requests.*')" icon="clipboard">Leave Requests</x-nav-link>
                    <x-nav-link :href="route('overtime.index')" :active="request()->routeIs('overtime.*')" icon="clock">Overtime</x-nav-link>
                    <x-nav-link :href="route('cash-advance-requests.index')" :active="request()->routeIs('cash-advance-requests.*')" icon="clipboard">Cash Advance Requests</x-nav-link>
                    <x-nav-link :href="route('my-reimbursements')" :active="request()->routeIs('my-reimbursements')" icon="money">My Reimbursement</x-nav-link>

                    @if ($showOrganization)
                        <p class="mb-1 mt-6 px-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-500">Organization</p>
                        @can('org.departments.manage')
                            <x-nav-link :href="route('org.departments')" :active="request()->routeIs('org.departments')" icon="building">Departments</x-nav-link>
                        @endcan
                        @can('org.positions.manage')
                            <x-nav-link :href="route('org.positions')" :active="request()->routeIs('org.positions')" icon="tag">Positions</x-nav-link>
                        @endcan
                    @endif

                    @if ($showPeople)
                        <p class="mb-1 mt-6 px-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-500">People</p>
                        @can('recruitment.manage')
                            <x-nav-link :href="route('recruitment.index')" :active="request()->routeIs('recruitment.*')" icon="user-plus">Recruitment</x-nav-link>
                        @endcan
                        @can('employees.manage')
                            <x-nav-link :href="route('employees.index')" :active="request()->routeIs('employees.*')" icon="people-group">Employees</x-nav-link>
                        @endcan
                        @can('users.manage')
                            <x-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')" icon="users">Users</x-nav-link>
                        @endcan
                        @can('schedules.manage')
                            <x-nav-link :href="route('schedules.index')" :active="request()->routeIs('schedules.*')" icon="calendar">Work Schedules</x-nav-link>
                        @endcan
                        @can('attendance.view_all')
                            <x-nav-link :href="route('attendance.dtr')" :active="request()->routeIs('attendance.dtr')" icon="clock">DTR</x-nav-link>
                        @endcan
                        @can('leave.types.manage')
                            <x-nav-link :href="route('leave-types.index')" :active="request()->routeIs('leave-types.*')" icon="document">Leave Types</x-nav-link>
                        @endcan
                        @can('cash_advances.manage')
                            <x-nav-link :href="route('cash-advances.index')" :active="request()->routeIs('cash-advances.*')" icon="money">Cash Advance Record</x-nav-link>
                        @endcan
                        @can('reimbursements.view_all')
                            <x-nav-link :href="route('reimbursements.index')" :active="request()->routeIs('reimbursements.index')" icon="clipboard">Reimbursement Record</x-nav-link>
                        @endcan
                    @endif

                    @if (auth()->user()->canAny(['payroll.runs.manage', 'payroll.settings.manage']))
                        <p class="mb-1 mt-6 px-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-500">Payroll</p>
                        @can('payroll.runs.manage')
                            <x-nav-link :href="route('payroll.index')" :active="request()->routeIs('payroll.index') || request()->routeIs('payroll.show') || request()->routeIs('payroll.payslip')" icon="money">Run Payroll</x-nav-link>
                        @endcan
                        @can('payroll.runs.manage')
                            <x-nav-link :href="route('payroll.thirteenth-month')" :active="request()->routeIs('payroll.thirteenth-month')" icon="money">13th Month</x-nav-link>
                        @endcan
                        @can('payroll.settings.manage')
                            <x-nav-link :href="route('payroll.settings')" :active="request()->routeIs('payroll.settings')" icon="tag">Payroll Settings</x-nav-link>
                        @endcan
                    @endif

                    @can('reports.view')
                        <p class="mb-1 mt-6 px-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-500">Reports</p>
                        <x-nav-link :href="route('reports.attendance-summary')" :active="request()->routeIs('reports.attendance-summary')" icon="chart">Attendance Summary</x-nav-link>
                    @endcan
                </nav>
            </aside>

            <div class="fixed inset-0 z-20 bg-ink-950/40 backdrop-blur-sm lg:hidden" x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"></div>

            {{-- Main --}}
            <div class="flex min-h-screen flex-1 flex-col lg:pl-0">
                @php
                    $pageTitle = match (true) {
                        request()->routeIs('dashboard') => 'Dashboard',
                        request()->routeIs('my-profile') => 'My Profile',
                        request()->routeIs('my-payslips*') => 'My Payslips',
                        request()->routeIs('attendance.punch') => 'My Attendance',
                        request()->routeIs('leave-requests.*') => 'Leave Requests',
                        request()->routeIs('overtime.*') => 'Overtime',
                        request()->routeIs('cash-advance-requests.*') => 'Cash Advance Requests',
                        request()->routeIs('my-reimbursements') => 'My Reimbursement',
                        request()->routeIs('reimbursements.*') => 'Reimbursement Record',
                        request()->routeIs('org.departments') => 'Departments',
                        request()->routeIs('org.positions') => 'Positions',
                        request()->routeIs('employees.*') => 'Employees',
                        request()->routeIs('users.*') => 'Users',
                        request()->routeIs('recruitment.*') => 'Recruitment',
                        request()->routeIs('schedules.*') => 'Work Schedules',
                        request()->routeIs('attendance.dtr') => 'DTR',
                        request()->routeIs('leave-types.*') => 'Leave Types',
                        request()->routeIs('cash-advances.*') => 'Cash Advance Record',
                        request()->routeIs('payroll.settings') => 'Payroll Settings',
                        request()->routeIs('payroll.thirteenth-month') => '13th Month Pay',
                        request()->routeIs('payroll.payslip') => 'Payslip',
                        request()->routeIs('payroll.*') => 'Payroll',
                        request()->routeIs('reports.attendance-summary') => 'Attendance Summary',
                        default => $title ?? '',
                    };
                @endphp
                <header class="sticky top-0 z-10 flex h-20 min-h-20 items-center justify-between border-b border-ink-200/80 bg-white/90 px-5 shadow-sm shadow-ink-200/50 backdrop-blur-xl sm:px-8 dark:border-white/10 dark:bg-ink-950/85 dark:shadow-black/20">
                    <div class="flex items-center gap-3">
                        <button @click="sidebarOpen = true" class="rounded-lg p-2 font-medium text-ink-500 hover:bg-ink-100 lg:hidden dark:hover:bg-white/10">
                            <x-icon name="chevron-down" class="h-5 w-5 rotate-90" />
                        </button>
                        <div>
                            <p class="hidden text-sm font-semibold uppercase tracking-[0.22em] text-brand-700 dark:text-brand-300 sm:block">CreatiVision HRIS</p>
                            @unless (request()->routeIs('org.departments') || request()->routeIs('org.positions') || request()->routeIs('employees.*'))
                                <h1 class="text-2xl font-bold leading-tight text-ink-950 dark:text-white">{{ $pageTitle }}</h1>
                            @endunless
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
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
                                " class="flex h-12 items-center gap-3 rounded-2xl border border-transparent py-1 pl-1.5 pr-3 transition hover:border-ink-200 hover:bg-white hover:shadow-sm dark:hover:border-white/10 dark:hover:bg-white/10">
                                <img src="{{ asset('images/logo-mark.png') }}" alt="" class="h-10 w-10 rounded-full border border-ink-200 bg-white object-contain p-1 dark:border-white/10">
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

                <div id="page-loading-bar" class="page-loading-bar" aria-hidden="true"></div>
                <div id="page-skeleton" class="page-skeleton hidden" aria-hidden="true">
                    <div class="space-y-6 p-4 sm:p-6">
                        <div>
                            <div class="skeleton-line h-8 w-56"></div>
                            <div class="skeleton-line mt-3 h-4 w-80 max-w-full"></div>
                        </div>

                        <div class="flex flex-wrap gap-4">
                            <div class="skeleton-card h-24 w-full sm:w-56"></div>
                            <div class="skeleton-card h-24 w-full sm:w-56"></div>
                            <div class="skeleton-card h-24 w-full sm:w-56"></div>
                        </div>

                        <div class="skeleton-card overflow-hidden rounded-2xl">
                            <div class="flex items-center justify-between border-b border-ink-200 px-6 py-5 dark:border-white/10">
                                <div class="skeleton-line h-6 w-48"></div>
                                <div class="skeleton-line h-10 w-72 max-w-full"></div>
                            </div>
                            <div class="grid gap-5 p-6 sm:grid-cols-3">
                                <div class="skeleton-line h-12 w-full"></div>
                                <div class="skeleton-line h-12 w-full"></div>
                                <div class="skeleton-line h-12 w-full"></div>
                                <div class="skeleton-line h-12 w-full"></div>
                                <div class="skeleton-line h-12 w-full"></div>
                                <div class="skeleton-line h-12 w-full"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <main id="page-content" class="page-transition flex-1 p-4 sm:p-6">
                    {{ $slot }}
                </main>

                <footer class="border-t border-ink-200/70 bg-white/75 px-4 py-4 text-center text-sm font-medium text-ink-500 backdrop-blur sm:px-6 dark:border-white/10 dark:bg-ink-950/70 dark:text-ink-400">
                    Copyright {{ now()->year }} | CreatiVision Outsourcing Team
                </footer>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
