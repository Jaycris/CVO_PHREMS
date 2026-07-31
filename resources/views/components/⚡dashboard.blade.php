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
        $isAdminHr = $user->hasAnyRole(['Admin', 'HR']);

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

<div class="-m-4 min-h-[calc(100vh-4rem)] space-y-6 p-4 sm:-m-6 sm:p-6">
    <section class="overflow-hidden rounded-2xl border border-white/10 bg-ink-950 shadow-sm shadow-ink-200/50 dark:shadow-black/20">
        <div class="relative p-6 sm:p-8">
            <div class="absolute inset-0"
                 style="background: radial-gradient(circle at 12% 8%, rgba(21,122,82,.48), transparent 25rem), radial-gradient(circle at 94% 8%, rgba(37,99,235,.18), transparent 24rem), linear-gradient(135deg, #020617 0%, #0f172a 52%, #052e23 100%);"></div>
            <img src="{{ asset('images/logo-mark.png') }}" alt="" class="pointer-events-none absolute -bottom-20 -left-16 h-72 w-72 object-contain opacity-[0.07]">
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-200">CreatiVision HRIS</p>
                    <h2 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $greeting }}, {{ explode(' ', auth()->user()->name)[0] }}</h2>
                    <p class="mt-3 max-w-2xl text-sm font-medium leading-6 text-ink-300">Here's what's happening across attendance, leave requests, and employee operations today.</p>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm sm:min-w-80">
                    <div class="rounded-lg border border-white/10 bg-white/10 p-4 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">Current role</p>
                        <p class="mt-1 font-bold text-white">{{ auth()->user()->getRoleNames()->join(', ') ?: 'No role' }}</p>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-white/10 p-4 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">Today</p>
                        <p class="mt-1 font-bold text-white">{{ $today->format('M d, Y') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if ($isAdminHr)
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Total Employees" :value="$stats['totalEmployees']" caption="All active employee records" color="brand" />
            <x-stat-card label="Present Today" :value="$stats['presentToday']" caption="Clocked in today" color="blue" />
            <x-stat-card label="Pending Approvals" :value="$stats['pendingApprovals']" caption="Awaiting Manager/CEO sign-off" color="amber" />
            <x-stat-card label="Departments" :value="$stats['departments']" caption="Across the company" color="brand" />
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card :padding="false" class="overflow-hidden rounded-2xl">
                <div class="border-b border-ink-200 bg-white px-6 py-4 dark:border-white/10 dark:bg-ink-900">
                    <p class="muted-label">Approvals</p>
                    <h3 class="mt-1 font-bold text-ink-950 dark:text-white">{{ $isAdminHr ? 'Recent Leave Requests' : 'My Recent Leave Requests' }}</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-100 dark:divide-white/10">
                        <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                            @forelse ($recentRequests as $request)
                                <tr class="text-sm transition hover:bg-ink-50 dark:hover:bg-white/5">
                                    <td class="whitespace-nowrap px-6 py-4 font-semibold text-ink-800 dark:text-white">
                                        @if ($isAdminHr)
                                            {{ $request->employee->fullName() ?: $request->employee->employee_id }}
                                        @else
                                            {{ $request->leaveType->name }}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 font-medium text-ink-500 dark:text-ink-400">
                                        {{ $request->start_date->format('M d') }} - {{ $request->end_date->format('M d, Y') }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <a href="{{ route('leave-requests.show', $request) }}" wire:navigate class="text-sm font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">View</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-6 py-8 text-center text-sm font-medium text-ink-500">No leave requests yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div>
            @if ($employee)
                <x-card class="rounded-2xl">
                    <p class="muted-label">Balances</p>
                    <h3 class="mb-4 mt-1 font-bold text-ink-950 dark:text-white">My Leave Credits</h3>
                    <div class="space-y-3">
                        @forelse ($leaveBalances as $balance)
                            <div class="flex items-center justify-between rounded-lg border border-ink-100 bg-ink-50 px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                                <span class="text-sm font-medium text-ink-600 dark:text-ink-300">{{ $balance['name'] }}</span>
                                <span class="text-sm font-bold text-ink-900 dark:text-white">{{ $balance['balance'] }} days</span>
                            </div>
                        @empty
                            <p class="text-sm font-medium text-ink-500">No leave types configured.</p>
                        @endforelse
                    </div>
                    <a href="{{ route('leave-requests.create') }}" wire:navigate class="mt-4 inline-flex items-center gap-1 text-sm font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">
                        Request leave <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </x-card>
            @else
                <x-card class="rounded-2xl">
                    <h3 class="font-bold text-ink-950 dark:text-white">Welcome</h3>
                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Your account isn't linked to an employee profile yet.</p>
                </x-card>
            @endif
        </div>
    </div>
</div>
