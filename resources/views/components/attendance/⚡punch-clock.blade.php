<?php

use App\Models\AttendanceDay;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Employee $employee;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $employee = Auth::user()->employee;

        abort_unless($employee, 403, 'No employee profile is linked to your account.');

        $this->employee = $employee;
    }

    public function openDay(): ?AttendanceDay
    {
        return $this->employee->attendanceDays()
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->latest('work_date')
            ->first();
    }

    public function timeIn(): void
    {
        if ($this->openDay()) {
            $this->errorMessage = 'You are already timed in.';

            return;
        }

        $now = now('Asia/Manila');
        $today = $now->toDateString();

        if ($this->employee->attendanceDays()->where('work_date', $today)->whereNotNull('time_out')->exists()) {
            $this->errorMessage = 'You have already completed your shift for today.';

            return;
        }

        $this->employee->attendanceDays()->create([
            'work_date' => $today,
            'time_in' => $now,
        ]);

        $this->errorMessage = null;
    }

    public function timeOut(): void
    {
        $day = $this->openDay();

        if (! $day) {
            $this->errorMessage = 'You are not timed in.';

            return;
        }

        if ($day->openBreak()) {
            $this->errorMessage = 'End your break before timing out.';

            return;
        }

        $day->update(['time_out' => now('Asia/Manila')]);
        $this->errorMessage = null;
    }

    public function startBreak(): void
    {
        $day = $this->openDay();

        if (! $day) {
            $this->errorMessage = 'You are not timed in.';

            return;
        }

        if ($day->openBreak()) {
            $this->errorMessage = 'You are already on break.';

            return;
        }

        $day->breaks()->create(['break_start' => now('Asia/Manila')]);
        $this->errorMessage = null;
    }

    public function endBreak(): void
    {
        $day = $this->openDay();
        $break = $day?->openBreak();

        if (! $break) {
            $this->errorMessage = 'You are not on break.';

            return;
        }

        $break->update(['break_end' => now('Asia/Manila')]);
        $this->errorMessage = null;
    }

    public function with(): array
    {
        $day = $this->openDay();
        $today = now('Asia/Manila');
        $scheduleAssignment = $this->employee->scheduleAssignmentForDate($today);
        $recentDays = $this->employee->attendanceDays()->latest('work_date')->limit(10)->get();

        // Which of these days have already been paid out. Loaded as one query
        // over the whole visible range and matched in memory, rather than a
        // lookup per row.
        $runs = $recentDays->isEmpty()
            ? collect()
            : \App\Models\PayrollRun::settledOver(
                $recentDays->min('work_date'),
                $recentDays->max('work_date')
            )->get();

        $payslips = $runs->isEmpty()
            ? collect()
            : \App\Models\Payslip::where('employee_id', $this->employee->id)
                ->whereIn('payroll_run_id', $runs->pluck('id'))
                ->get()
                ->keyBy('payroll_run_id');

        $payrollFor = fn ($date) => $runs->first(fn ($run) => $run->coversDate($date));

        return [
            'day' => $day,
            'onBreak' => $day?->openBreak() !== null,
            'recentDays' => $recentDays,
            'today' => $today,
            'schedule' => $scheduleAssignment?->workSchedule,
            'payrollFor' => $payrollFor,
            'payslips' => $payslips,
            // The most recent settled period, for the banner.
            'latestSettled' => $runs->sortByDesc('pay_date')->first(),
        ];
    }
};
?>

<div class="space-y-7">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">My Attendance</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">Track your daily shift, breaks, and recent attendance logs.</p>
        </div>
    </div>

    @if ($errorMessage)
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 dark:border-red-400/20 dark:bg-red-500/10 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    {{-- Payroll for these days is settled. Said here because this is the page
         someone opens when they want to know whether their hours counted. --}}
    @if ($latestSettled)
        @php($settledSlip = $payslips->get($latestSettled->id))
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 dark:border-brand-400/20 dark:bg-brand-500/10">
            <div>
                <p class="text-sm font-bold text-brand-800 dark:text-brand-200">
                    Payroll for {{ $latestSettled->periodLabel() }} has been processed
                </p>
                <p class="mt-0.5 text-sm font-medium text-brand-700 dark:text-brand-300">
                    @if ($latestSettled->status === 'paid')
                        Released on {{ $latestSettled->pay_date->format('F j, Y') }}.
                    @else
                        Pay date {{ $latestSettled->pay_date->format('F j, Y') }}.
                    @endif
                    Check it and tell HR within three working days if anything looks wrong.
                </p>
            </div>
            @if ($settledSlip)
                <x-button as="a" href="{{ route('my-payslips') }}" wire:navigate variant="secondary">View Payslip</x-button>
            @endif
        </div>
    @endif

    <section class="grid gap-5 xl:grid-cols-[1fr_1.1fr]">
        <div class="overflow-hidden rounded-2xl border border-white/10 bg-ink-950 shadow-sm">
            <div class="relative min-h-full p-6 sm:p-8" x-data="{ time: new Date() }" x-init="setInterval(() => time = new Date(), 1000)">
                <div class="absolute inset-0"
                     style="background: radial-gradient(circle at 12% 8%, rgba(21,122,82,.48), transparent 22rem), radial-gradient(circle at 94% 8%, rgba(37,99,235,.18), transparent 22rem), linear-gradient(135deg, #020617 0%, #0f172a 52%, #052e23 100%);"></div>
                <img src="{{ asset('images/logo-mark.png') }}" alt="" class="pointer-events-none absolute -bottom-16 -left-14 h-64 w-64 object-contain opacity-[0.07]">

                <div class="relative flex h-full min-h-80 flex-col justify-between gap-8">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-200">Philippine Time</p>
                        <p class="mt-4 text-5xl font-black tracking-tight text-white sm:text-6xl" x-text="time.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></p>
                        <p class="mt-3 text-sm font-medium text-ink-300">{{ $today->format('l, F j, Y') }}</p>
                    </div>

                    <div class="rounded-xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-ink-400">Current Status</p>
                        <p class="mt-2 text-xl font-bold text-white">
                            @if (! $day)
                                Not timed in
                            @elseif ($onBreak)
                                On break
                            @else
                                Working
                            @endif
                        </p>
                        <p class="mt-1 text-sm leading-6 text-ink-300">
                            @if (! $day)
                                Start your shift when you are ready.
                            @elseif ($onBreak)
                                Break started at {{ $day->openBreak()->break_start->format('g:i A') }}.
                            @else
                                Timed in since {{ $day->time_in->format('g:i A') }}.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <x-card class="rounded-2xl">
            <div class="flex flex-col gap-6">
                <div>
                    <p class="muted-label">Shift Controls</p>
                    <h2 class="mt-1 text-2xl font-bold text-ink-950 dark:text-white">{{ $employee->fullName() ?: $employee->employee_id }}</h2>
                    <p class="mt-2 text-sm font-medium text-ink-500 dark:text-ink-400">
                        @if (! $day)
                            No active time entry yet. Your assigned schedule is {{ $schedule ? $schedule->name . ' (' . $schedule->start_time->format('g:i A') . ' - ' . $schedule->end_time->format('g:i A') . ')' : 'not set' }}.
                        @elseif ($onBreak)
                            End your break before timing out.
                        @else
                            Your shift is active for {{ $day->work_date->format('M d, Y') }}.
                        @endif
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Time In</p>
                        <p class="mt-2 text-lg font-bold text-ink-950 dark:text-white">{{ $day?->time_in?->format('g:i A') ?? '--' }}</p>
                    </div>
                    <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Time Out</p>
                        <p class="mt-2 text-lg font-bold text-ink-950 dark:text-white">{{ $day?->time_out?->format('g:i A') ?? '--' }}</p>
                    </div>
                    <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Break</p>
                        <p class="mt-2 text-lg font-bold text-ink-950 dark:text-white">{{ $day ? $day->totalBreakMinutes() . ' min' : '--' }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 dark:border-brand-400/20 dark:bg-brand-400/10">
                    <p class="text-xs font-bold uppercase tracking-wide text-brand-700 dark:text-brand-300">Assigned Schedule</p>
                    <p class="mt-1 text-sm font-semibold text-ink-800 dark:text-white">
                        @if ($schedule)
                            {{ $schedule->name }} · {{ $schedule->start_time->format('g:i A') }} - {{ $schedule->end_time->format('g:i A') }}
                        @else
                            No schedule assigned for today.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    @if (! $day)
                        <x-button wire:click="timeIn" class="h-12 rounded-xl px-6">
                            <x-icon name="clock" class="h-4 w-4" /> Time In
                        </x-button>
                    @else
                        @if (! $onBreak)
                            <x-button wire:click="startBreak" variant="secondary" class="h-12 rounded-xl px-6">
                                Start Break
                            </x-button>
                            <x-button wire:click="timeOut" variant="danger" class="h-12 rounded-xl px-6">
                                Time Out
                            </x-button>
                        @else
                            <x-button wire:click="endBreak" class="h-12 rounded-xl px-6">
                                End Break
                            </x-button>
                        @endif
                    @endif
                </div>
            </div>
        </x-card>
    </section>

    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="border-b border-ink-200 px-6 py-5 dark:border-white/10">
            <h2 class="text-xl font-bold text-ink-950 dark:text-white">Recent Attendance</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                <thead class="bg-ink-50 dark:bg-white/5">
                    <tr>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Date</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Time In</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Time Out</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Late</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Break</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Payroll</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                    @forelse ($recentDays as $recentDay)
                        <tr wire:key="day-{{ $recentDay->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                            <td class="px-5 py-4 font-medium text-[#526783] dark:text-white">{{ $recentDay->work_date->format('M d, Y') }}</td>
                            <td class="px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $recentDay->time_in?->format('g:i A') ?? '--' }}</td>
                            <td class="px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $recentDay->time_out?->format('g:i A') ?? '--' }}</td>
                            <td class="px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $recentDay->lateMinutes() !== null ? $recentDay->lateMinutes() . ' min' : '--' }}</td>
                            <td class="px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $recentDay->totalBreakMinutes() }} min @if($recentDay->overBreakMinutes() > 0) <span class="text-red-600 dark:text-red-400">(+{{ $recentDay->overBreakMinutes() }} over)</span> @endif</td>
                            <td class="px-5 py-4">
                                @php($dayRun = $payrollFor($recentDay->work_date))
                                @if (! $dayRun)
                                    <span class="text-sm font-medium text-ink-500">Not yet processed</span>
                                @else
                                    <x-badge :color="$dayRun->status === 'paid' ? 'green' : 'brand'">
                                        {{ $dayRun->status === 'paid' ? 'Paid' : 'Processed' }}
                                    </x-badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center font-medium text-ink-500">No attendance records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
