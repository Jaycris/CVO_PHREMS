<?php

use App\Models\AttendanceDay;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $user = Auth::user();
        $employee = $user->employee;
        $isAdminHr = $user->can('employees.manage');

        $today = now('Asia/Manila');
        $hour = $today->hour;
        $greeting = match (true) {
            $hour < 12 => 'Good Morning',
            $hour < 18 => 'Good Afternoon',
            default => 'Good Evening',
        };

        return [
            'greeting' => $greeting,
            'today' => $today,
            'isAdminHr' => $isAdminHr,
            'employee' => $employee,
            'stats' => $isAdminHr ? [
                'totalEmployees' => Employee::count(),
                'presentToday' => AttendanceDay::whereDate('work_date', $today->toDateString())->whereNotNull('time_in')->count(),
                'pendingApprovals' => LeaveRequest::whereIn('status', ['pending_manager', 'pending_ceo'])->count(),
                'departments' => Department::count(),
            ] : null,
            'leaveBalances' => $employee
                ? LeaveType::where('is_active', true)->orderBy('name')->get()->map(fn (LeaveType $t) => [
                    'code' => $t->code,
                    'name' => $t->name,
                    'balance' => $employee->leaveBalance($t),
                ])
                : collect(),
            'recentRequests' => $isAdminHr
                ? LeaveRequest::with(['employee', 'leaveType'])->latest()->limit(5)->get()
                : ($employee ? LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->latest()->limit(5)->get() : collect()),
        ];
    }
};
?>

<div class="space-y-5">
    <section class="flex flex-col gap-4 border-b border-ink-200 pb-5 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
        <div>
            <h1 class="text-2xl font-bold text-ink-950 sm:text-3xl dark:text-white">Your HR Dashboard</h1>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Welcome back, {{ explode(' ', auth()->user()->name)[0] }}. Here is your overview for {{ $today->format('F j, Y') }}.</p>
        </div>
        <div class="flex items-center gap-3 rounded-lg border border-ink-200 bg-white px-3 py-2.5 shadow-sm dark:border-white/10 dark:bg-ink-900">
            @if ($employee)
                <x-avatar :employee="$employee" size="md" class="!h-11 !w-11" />
            @else
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-ink-950 dark:text-white">{{ $employee?->fullName() ?: auth()->user()->name }}</p>
                <p class="truncate text-xs font-medium text-ink-500 dark:text-ink-400">{{ $employee?->employee_id ?? (auth()->user()->getRoleNames()->join(', ') ?: 'HRIS user') }}</p>
            </div>
        </div>
    </section>

    @if ($isAdminHr)
        <section class="grid grid-cols-2 overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm lg:grid-cols-4 dark:border-white/10 dark:bg-ink-900">
            @foreach ([
                ['label' => 'Total Employees', 'value' => $stats['totalEmployees'], 'caption' => 'Employee records', 'icon' => 'people-group'],
                ['label' => 'Present Today', 'value' => $stats['presentToday'], 'caption' => 'Timed in today', 'icon' => 'clock'],
                ['label' => 'Pending Approvals', 'value' => $stats['pendingApprovals'], 'caption' => 'Awaiting review', 'icon' => 'clipboard'],
                ['label' => 'Departments', 'value' => $stats['departments'], 'caption' => 'Active teams', 'icon' => 'building'],
            ] as $index => $stat)
                <div class="flex items-center gap-3 border-ink-200 px-4 py-3.5 dark:border-white/10 {{ $index % 2 === 0 ? 'border-r' : '' }} {{ $index > 1 ? 'border-t lg:border-t-0' : '' }} {{ $index > 0 ? 'lg:border-l' : '' }}">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300">
                        <x-icon :name="$stat['icon']" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xl font-bold leading-none text-ink-950 dark:text-white">{{ $stat['value'] }}</p>
                        <p class="mt-1 truncate text-xs font-semibold text-ink-700 dark:text-ink-200">{{ $stat['label'] }}</p>
                        <p class="truncate text-[11px] font-medium text-ink-400">{{ $stat['caption'] }}</p>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">
        <aside class="space-y-5 xl:col-span-3">
            <section class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm dark:border-white/10 dark:bg-ink-900">
                <div class="border-b border-ink-200 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-bold text-ink-950 dark:text-white">My Profile</h2>
                </div>
                @if ($employee)
                    <div class="p-4">
                        <div class="flex items-center gap-3">
                            <x-avatar :employee="$employee" size="lg" class="!h-14 !w-14" />
                            <div class="min-w-0">
                                <p class="truncate font-bold text-ink-950 dark:text-white">{{ $employee->fullName() ?: auth()->user()->name }}</p>
                                <p class="truncate text-xs font-medium text-ink-500 dark:text-ink-400">{{ $employee->company_email ?: auth()->user()->email }}</p>
                            </div>
                        </div>
                        <dl class="mt-4 space-y-2.5 text-xs">
                            <div class="flex items-start justify-between gap-3"><dt class="font-medium text-ink-400">Employee ID</dt><dd class="font-semibold text-ink-700 dark:text-ink-200">{{ $employee->employee_id }}</dd></div>
                            <div class="flex items-start justify-between gap-3"><dt class="font-medium text-ink-400">Status</dt><dd class="font-semibold text-ink-700 dark:text-ink-200">{{ $employee->employment_status ?: 'Not set' }}</dd></div>
                            <div class="flex items-start justify-between gap-3"><dt class="font-medium text-ink-400">Hire date</dt><dd class="font-semibold text-ink-700 dark:text-ink-200">{{ $employee->hire_date?->format('M d, Y') ?? 'Not set' }}</dd></div>
                        </dl>
                        <a href="{{ route('my-profile') }}" wire:navigate class="mt-4 inline-flex items-center gap-1 text-xs font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">View full profile <x-icon name="arrow-right" class="h-3.5 w-3.5" /></a>
                    </div>
                @else
                    <p class="p-4 text-sm font-medium text-ink-500">Your account is not linked to an employee profile yet.</p>
                @endif
            </section>

            <section class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm dark:border-white/10 dark:bg-ink-900">
                <div class="border-b border-ink-200 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-bold text-ink-950 dark:text-white">Quick Actions</h2>
                </div>
                <div class="grid grid-cols-2 gap-2 p-3">
                    @foreach ([
                        ['route' => 'leave-requests.create', 'label' => 'Request Leave', 'icon' => 'calendar'],
                        ['route' => 'attendance.punch', 'label' => 'Attendance', 'icon' => 'clock'],
                        ['route' => 'overtime.create', 'label' => 'Overtime', 'icon' => 'trending-up'],
                        ['route' => 'requests.index', 'label' => 'New Request', 'icon' => 'clipboard'],
                    ] as $action)
                        <a href="{{ route($action['route']) }}" wire:navigate class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-lg border border-ink-200 bg-ink-50 px-2 py-3 text-center text-xs font-bold text-ink-700 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-800 dark:border-white/10 dark:bg-white/5 dark:text-ink-200 dark:hover:bg-brand-400/10 dark:hover:text-brand-200">
                            <x-icon :name="$action['icon']" class="h-5 w-5" />
                            <span>{{ $action['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        </aside>

        <main class="space-y-5 xl:col-span-6">
            <section class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm dark:border-white/10 dark:bg-ink-900">
                <div class="flex items-center justify-between border-b border-ink-200 px-4 py-3 dark:border-white/10">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-700 dark:text-brand-300">Balances</p>
                        <h2 class="mt-0.5 text-sm font-bold text-ink-950 dark:text-white">My Time Off</h2>
                    </div>
                    <a href="{{ route('leave-requests.create') }}" wire:navigate class="text-xs font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">Request leave</a>
                </div>
                <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($leaveBalances->take(6) as $index => $balance)
                        @php
                            $tones = [
                                ['bg' => 'bg-brand-50 dark:bg-brand-400/10', 'text' => 'text-brand-700 dark:text-brand-300', 'bar' => 'bg-brand-600'],
                                ['bg' => 'bg-blue-50 dark:bg-blue-400/10', 'text' => 'text-blue-700 dark:text-blue-300', 'bar' => 'bg-blue-500'],
                                ['bg' => 'bg-amber-50 dark:bg-amber-400/10', 'text' => 'text-amber-700 dark:text-amber-300', 'bar' => 'bg-amber-500'],
                            ];
                            $tone = $tones[$index % count($tones)];
                            $percentage = min(100, max(4, ((float) $balance['balance'] / max(1, (float) $leaveBalances->max('balance'))) * 100));
                        @endphp
                        <div class="rounded-lg border border-ink-200 p-3 dark:border-white/10 {{ $tone['bg'] }}">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-xs font-bold text-ink-700 dark:text-ink-200">{{ $balance['name'] }}</p>
                                <span class="text-[10px] font-bold uppercase {{ $tone['text'] }}">{{ $balance['code'] }}</span>
                            </div>
                            <p class="mt-3 text-2xl font-bold leading-none text-ink-950 dark:text-white">{{ rtrim(rtrim(number_format($balance['balance'], 2), '0'), '.') }}</p>
                            <p class="mt-1 text-[11px] font-medium text-ink-500 dark:text-ink-400">days available</p>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/80 dark:bg-black/20"><div class="h-full rounded-full {{ $tone['bar'] }}" style="width: {{ $percentage }}%"></div></div>
                        </div>
                    @empty
                        <p class="sm:col-span-2 lg:col-span-3 py-5 text-center text-sm font-medium text-ink-500">No leave balances are available yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm dark:border-white/10 dark:bg-ink-900">
                <div class="flex items-center justify-between border-b border-ink-200 px-4 py-3 dark:border-white/10">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-700 dark:text-brand-300">Activity</p>
                        <h2 class="mt-0.5 text-sm font-bold text-ink-950 dark:text-white">{{ $isAdminHr ? 'Recent Leave Requests' : 'My Recent Leave Requests' }}</h2>
                    </div>
                    <a href="{{ route('leave-requests.index') }}" wire:navigate class="text-xs font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">View all</a>
                </div>
                <div class="divide-y divide-ink-100 dark:divide-white/10">
                    @forelse ($recentRequests as $request)
                        <a href="{{ route('leave-requests.show', $request) }}" wire:navigate class="grid grid-cols-[1fr_auto] items-center gap-3 px-4 py-3 transition hover:bg-ink-50 dark:hover:bg-white/5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-ink-800 dark:text-white">{{ $isAdminHr ? ($request->employee->fullName() ?: $request->employee->employee_id) : $request->leaveType->name }}</p>
                                <p class="mt-0.5 text-xs font-medium text-ink-500 dark:text-ink-400">{{ $request->start_date->format('M d') }} - {{ $request->end_date->format('M d, Y') }}</p>
                            </div>
                            <x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge>
                        </a>
                    @empty
                        <p class="px-4 py-8 text-center text-sm font-medium text-ink-500">No leave requests yet.</p>
                    @endforelse
                </div>
            </section>
        </main>

        <aside class="space-y-5 xl:col-span-3">
            <section class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm dark:border-white/10 dark:bg-ink-900">
                <div class="border-b border-ink-200 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-bold text-ink-950 dark:text-white">Employee Self-Service</h2>
                </div>
                <div class="divide-y divide-ink-100 dark:divide-white/10">
                    @foreach ([
                        ['route' => 'my-payslips', 'title' => 'Recent Payslips', 'caption' => 'View released payroll statements', 'icon' => 'money'],
                        ['route' => 'my-commission', 'title' => 'My Commission', 'caption' => 'Review commission slips', 'icon' => 'chart'],
                        ['route' => 'requests.index', 'title' => 'Pending Requests', 'caption' => 'Track submitted requests', 'icon' => 'clipboard'],
                        ['route' => 'my-reimbursements', 'title' => 'Reimbursements', 'caption' => 'Check awaiting payments', 'icon' => 'document'],
                    ] as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate class="flex items-center gap-3 px-4 py-3.5 transition hover:bg-ink-50 dark:hover:bg-white/5">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink-50 text-brand-700 dark:bg-white/5 dark:text-brand-300"><x-icon :name="$item['icon']" class="h-4.5 w-4.5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-ink-800 dark:text-white">{{ $item['title'] }}</span>
                                <span class="block truncate text-xs font-medium text-ink-500 dark:text-ink-400">{{ $item['caption'] }}</span>
                            </span>
                            <x-icon name="arrow-right" class="h-4 w-4 shrink-0 text-ink-400" />
                        </a>
                    @endforeach
                </div>
            </section>

            @if ($employee)
                <section class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm dark:border-white/10 dark:bg-ink-900">
                    <div class="border-b border-ink-200 px-4 py-3 dark:border-white/10"><h2 class="text-sm font-bold text-ink-950 dark:text-white">Employment</h2></div>
                    <dl class="space-y-3 p-4 text-xs">
                        <div><dt class="font-medium text-ink-400">Current status</dt><dd class="mt-1 font-bold text-ink-800 dark:text-white">{{ $employee->employment_status ?: 'Not set' }}</dd></div>
                        <div><dt class="font-medium text-ink-400">Employment type</dt><dd class="mt-1 font-bold text-ink-800 dark:text-white">{{ $employee->employment_type ?: 'Not set' }}</dd></div>
                        <div><dt class="font-medium text-ink-400">Company email</dt><dd class="mt-1 break-all font-bold text-ink-800 dark:text-white">{{ $employee->company_email ?: auth()->user()->email }}</dd></div>
                    </dl>
                </section>
            @endif
        </aside>
    </div>
</div>
