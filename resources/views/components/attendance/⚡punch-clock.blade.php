<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Services\Attendance\PunchLocationPolicy;
use App\Services\Attendance\ShiftDateResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public Employee $employee;
    public ?string $errorMessage = null;

    /** The month being looked at, as Y-m. Defaults to the one in progress. */
    public string $viewMonth = '';

    public function mount(): void
    {
        $employee = Auth::user()->employee;

        abort_unless($employee, 403, 'No employee profile is linked to your account.');

        $this->employee = $employee;
        $this->viewMonth = now('Asia/Manila')->format('Y-m');
    }

    /**
     * Only the months that actually hold attendance, newest first, plus the
     * month in progress so there is always something selected.
     *
     * Counting back from the hire date instead would list months from before
     * the company kept attendance here at all — someone hired in January would
     * be offered January through June and find every one of them empty.
     */
    public function selectableMonths(): array
    {
        $current = now('Asia/Manila')->startOfMonth();

        return $this->employee->attendanceDays()
            ->select('work_date')
            ->distinct()
            ->pluck('work_date')
            ->map(fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('Y-m'))
            ->prepend($current->format('Y-m'))
            ->unique()
            ->sortDesc()
            ->mapWithKeys(fn ($month) => [
                $month => \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('F Y'),
            ])
            ->all();
    }

    /**
     * The month comes off a dropdown, but Livewire will accept whatever the
     * browser sends it. An unrecognised value falls back to the month in
     * progress rather than reaching Carbon and throwing.
     */
    public function updatedViewMonth(): void
    {
        if (! array_key_exists($this->viewMonth, $this->selectableMonths())) {
            $this->viewMonth = now('Asia/Manila')->format('Y-m');
        }

        // Otherwise picking a short month while on page 2 lands on nothing.
        $this->resetPage('days');
    }

    public function openDay(): ?AttendanceDay
    {
        return $this->employee->attendanceDays()
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->latest('work_date')
            ->first();
    }

    /**
     * Refuses a punch from somebody who is not on the punch clock at all.
     *
     * The buttons are already hidden for them, but a tab left open from before
     * the change would still have them — and a stray punch would put a day of
     * attendance on a record payroll deliberately ignores.
     */
    protected function usesPunchClock(): bool
    {
        if ($this->employee->tracks_attendance) {
            return true;
        }

        $this->errorMessage = 'Your work is on fixed pay, so you do not clock in. Refresh the page.';

        return false;
    }

    /**
     * Refuses the punch when an on-site employee is not on an office network.
     *
     * Checked on all four actions rather than only on Time In. Guarding the
     * way in but not the way out would let someone clock in at the office and
     * then take their breaks and clock out from a bus.
     */
    protected function atAllowedLocation(): bool
    {
        $policy = app(PunchLocationPolicy::class);
        $ip = request()->ip();

        if ($policy->allows($this->employee, $ip)) {
            return true;
        }

        /*
         * The address goes to the log, not to the person being refused.
         *
         * Whoever fixes a changed office line still needs the number, and this
         * is where they should find it. Telling the employee instead was
         * handing somebody at home the exact value to ask HR to approve.
         */
        \Illuminate\Support\Facades\Log::warning('Punch refused: outside the office network.', [
            'employee_id' => $this->employee->employee_id,
            'name' => $this->employee->fullName(),
            'ip' => $ip,
        ]);

        $this->errorMessage = $policy->refusalMessage();

        return false;
    }

    public function timeIn(): void
    {
        if (! $this->usesPunchClock() || ! $this->atAllowedLocation()) {
            return;
        }

        $now = now('Asia/Manila');

        /*
         * The shift this punch belongs to, which is not always today.
         *
         * A night shift crosses midnight, so somebody coming in at 1 AM is late
         * for last night's shift rather than early for tonight's. Filing it
         * under today used to close today before it began, and the punch at
         * 10 PM that same evening was refused.
         */
        $shiftDate = app(ShiftDateResolver::class)->forPunch($this->employee, $now);

        $open = $this->openDay();

        /*
         * Only the shift being started can block it.
         *
         * This used to refuse whenever any day anywhere was left open, and a
         * forgotten time out is common — somebody clocks in at 2:39 AM, goes
         * home without clocking out, and from then on every punch is met with
         * "You are already timed in". Forever, because the only thing that
         * closes a day is the person who can no longer reach the button.
         *
         * The stale day is deliberately left alone rather than closed here.
         * Inventing an end time would put hours nobody worked into somebody's
         * pay; it stays open, shows on the DTR, and HR corrects it.
         */
        if ($open && $open->work_date->toDateString() === $shiftDate) {
            $this->errorMessage = 'You are already timed in.';

            return;
        }

        // whereDate, not where: a date column compared against a plain string
        // matches on MySQL and misses on other drivers, and a missed match here
        // means trying to start a shift that is already finished.
        if ($this->employee->attendanceDays()->whereDate('work_date', $shiftDate)->whereNotNull('time_out')->exists()) {
            $this->errorMessage = 'You have already completed your shift for today.';

            return;
        }

        $this->employee->attendanceDays()->create([
            'work_date' => $shiftDate,
            'time_in' => $now,
        ]);

        $this->errorMessage = null;
    }

    public function timeOut(): void
    {
        if (! $this->usesPunchClock() || ! $this->atAllowedLocation()) {
            return;
        }

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
        if (! $this->usesPunchClock() || ! $this->atAllowedLocation()) {
            return;
        }

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
        if (! $this->usesPunchClock() || ! $this->atAllowedLocation()) {
            return;
        }

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
        /*
         * A whole month rather than the last ten days. Someone checking whether
         * a shift was recorded is usually looking at a payslip that covers a
         * fortnight, so ten rows was never enough to answer the question.
         *
         * The month's schedule assignments are loaded once and matched in
         * memory, the same reason the payroll aggregator does it — otherwise
         * every row asks the database for its own schedule twice over.
         */
        $month = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->viewMonth)->startOfMonth();

        $monthDays = $this->employee->attendanceDays()
            ->with('breaks')
            ->whereDate('work_date', '>=', $month->toDateString())
            ->whereDate('work_date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->orderByDesc('work_date')
            ->get();

        $assignments = \App\Models\EmployeeScheduleAssignment::with('workSchedule')
            ->where('employee_id', $this->employee->id)
            ->orderByDesc('effective_start_date')
            ->orderByDesc('id')
            ->get();

        $assignmentFor = fn ($date) => $assignments->first(function ($a) use ($date) {
            $on = $date->toDateString();

            return $a->effective_start_date->toDateString() <= $on
                && ($a->effective_end_date === null || $a->effective_end_date->toDateString() >= $on);
        });

        $monthTotals = [
            'days' => $monthDays->count(),
            'late' => 0,
            'over_break' => 0,
            'unclosed' => 0,
        ];

        foreach ($monthDays as $d) {
            $assignment = $assignmentFor($d->work_date);
            $monthTotals['late'] += (int) ($d->lateMinutes($assignment) ?? 0);
            $monthTotals['over_break'] += $d->overBreakMinutes($assignment);
            $monthTotals['unclosed'] += $d->time_out ? 0 : 1;
        }

        /*
         * The whole month is fetched, totalled, and only then split into pages.
         * The summary above the table has to describe the month, not the ten
         * rows on screen — paging the query instead would make "total late"
         * change every time you pressed Next.
         */
        $pagedDays = $this->paginateCollection($monthDays, 'days');

        $overtimeHours = (float) \App\Models\OvertimeRequest::where('employee_id', $this->employee->id)
            ->where('status', 'approved')
            ->whereDate('work_date', '>=', $month->toDateString())
            ->whereDate('work_date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->sum('hours_approved');

        /*
         * Payroll status is answered once for the period rather than once per
         * row. Repeating "Not yet processed" down every line of the table is
         * noise, and it gets worse the more attendance there is.
         */
        $resolver = new \App\Services\Payroll\PayrollPeriodResolver();
        $current = $resolver->containing($today);
        $previous = $resolver->previous($current);

        /*
         * Two fixed periods rather than "current" and "whatever was paid last".
         *
         * The cutoff ends on the 25th but is not paid until the 30th, so for
         * those five days the period an employee most wants to see is the one
         * they have just finished — and it is no longer the current one. Pinning
         * the second card to the previous period keeps it on screen through that
         * gap, and stops both cards showing the same period the rest of the time.
         *
         * Within each card, two different questions are answered from two
         * different things: whether payroll has been done comes from the run's
         * status, while whether the payslip can be opened comes from
         * notified_at, which HR sets by pressing Send.
         */
        $runFor = function (array $period) {
            return \App\Models\Payslip::with('payrollRun')
                ->where('employee_id', $this->employee->id)
                ->whereHas('payrollRun', fn ($q) => $q
                    ->whereIn('status', ['finalized', 'paid'])
                    ->whereDate('period_start', $period['start']->toDateString())
                    ->whereDate('period_end', $period['end']->toDateString()))
                ->first();
        };

        $currentSlip = $runFor($current);
        $previousSlip = $runFor($previous);

        return [
            'day' => $day,
            'onBreak' => $day?->openBreak() !== null,
            'monthDays' => $pagedDays,
            'monthTotals' => $monthTotals,
            'monthOvertime' => $overtimeHours,
            'viewingMonth' => $month,
            'assignmentFor' => $assignmentFor,
            'monthOptions' => $this->selectableMonths(),
            'today' => $today,
            'schedule' => $scheduleAssignment?->workSchedule,
            'currentPeriod' => $current,
            'currentSettled' => $currentSlip?->payrollRun,
            'currentReleased' => $currentSlip?->notified_at !== null,
            'previousPeriod' => $previous,
            'previousSettled' => $previousSlip?->payrollRun,
            'previousReleased' => $previousSlip?->notified_at !== null,
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

    {{-- One line for the period being worked now, one for the last one paid.
         This is the page someone opens to find out whether their hours
         counted, so it answers that before the table does. --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-ink-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-ink-900">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[#778599]">Current period</p>
            <p class="mt-1 text-sm font-bold text-ink-950 dark:text-white">
                {{ $currentPeriod['start']->format('M j') }} – {{ $currentPeriod['end']->format('M j, Y') }}
            </p>
            <div class="mt-1.5 flex flex-wrap items-center gap-3">
                @if ($currentSettled)
                    <x-badge :color="$currentSettled->status === 'paid' ? 'green' : 'brand'">
                        {{ $currentSettled->status === 'paid' ? 'Paid' : 'Processed' }}
                    </x-badge>
                    {{-- A period can be processed and released while it is still
                         the current one, when HR runs payroll on the last day. --}}
                    @if ($currentReleased)
                        <a href="{{ route('my-payslips') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">View payslip</a>
                    @endif
                @else
                    <x-badge color="neutral">Not yet processed</x-badge>
                @endif
            </div>
            <p class="mt-1.5 text-xs font-medium text-[#778599]">
                @if ($currentSettled && ! $currentReleased)
                    Your payslip will be sent shortly.
                @else
                    Pay date {{ $currentPeriod['pay_date']->format('F j, Y') }}
                @endif
            </p>
        </div>

        <div class="rounded-xl border border-ink-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-ink-900">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[#778599]">Previous period</p>
            <p class="mt-1 text-sm font-bold text-ink-950 dark:text-white">
                {{ $previousPeriod['start']->format('M j') }} – {{ $previousPeriod['end']->format('M j, Y') }}
            </p>
            <div class="mt-1.5 flex flex-wrap items-center gap-3">
                @if ($previousSettled)
                    <x-badge :color="$previousSettled->status === 'paid' ? 'green' : 'brand'">
                        {{ $previousSettled->status === 'paid' ? 'Paid' : 'Processed' }}
                    </x-badge>
                    @if ($previousReleased)
                        <a href="{{ route('my-payslips') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">View payslip</a>
                    @endif
                @else
                    <x-badge color="neutral">Not yet processed</x-badge>
                @endif
            </div>
            <p class="mt-1.5 text-xs font-medium text-[#778599]">
                @if ($previousSettled && $previousReleased)
                    Tell HR within three working days if anything looks wrong.
                @elseif ($previousSettled)
                    Your payslip will be sent shortly.
                @else
                    Pay date {{ $previousPeriod['pay_date']->format('F j, Y') }}
                @endif
            </p>
        </div>
    </div>

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

                {{-- Somebody who does not use the punch clock is told so
                     plainly, rather than being shown three buttons that would
                     refuse them. Their history stays visible below, so a move
                     onto fixed pay does not hide what came before it. --}}
                @if (! $this->employee->tracks_attendance)
                    <div class="rounded-xl bg-[#f8fafc] px-5 py-4 dark:bg-neutral-800/50">
                        <p class="text-sm font-bold text-[#0f172a] dark:text-white">You do not need to clock in</p>
                        <p class="mt-1 text-sm font-medium text-[#778599]">
                            Your work is on fixed pay, so payroll does not measure your hours and no absence is
                            ever deducted. Leave and overtime are filed the same way as everyone else.
                        </p>
                    </div>
                @else
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
                @endif
            </div>
        </x-card>
    </section>

    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="border-b border-ink-200 px-6 py-5 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold text-ink-950 dark:text-white">My Attendance History</h2>
                    <p class="mt-1 text-sm font-medium text-[#778599]">
                        Pick a month to see the days recorded in it.
                    </p>
                </div>

                <div>
                    <label for="attendance-month" class="sr-only">Month</label>
                    <select id="attendance-month" wire:model.live="viewMonth"
                            class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-semibold text-[#526783] shadow-sm transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                        @foreach ($monthOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- The month at a glance, so the answer to "how many days did I
                 work" does not require counting rows. --}}
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    'Days recorded' => $monthTotals['days'],
                    'Total late' => $monthTotals['late'] . ' min',
                    'Over break' => $monthTotals['over_break'] . ' min',
                    'Approved overtime' => rtrim(rtrim(number_format($monthOvertime, 2), '0'), '.') . ' h',
                ] as $label => $value)
                    <div>
                        <p class="text-xs font-medium text-[#778599]">{{ $label }}</p>
                        <p class="mt-0.5 text-lg font-bold text-ink-950 dark:text-white tabular-nums">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            @if ($monthTotals['unclosed'] > 0)
                <p class="mt-3 text-xs font-medium text-amber-600 dark:text-amber-400">
                    {{ $monthTotals['unclosed'] }} day(s) have no time out. Tell HR — payroll cannot measure a day that was never closed.
                </p>
            @endif
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
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                    @forelse ($monthDays as $recentDay)
                        @php($dayAssignment = $assignmentFor($recentDay->work_date))
                        @php($late = $recentDay->lateMinutes($dayAssignment))
                        <tr wire:key="day-{{ $recentDay->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                            <td class="px-5 py-4 font-medium text-[#526783] dark:text-white">
                                {{ $recentDay->work_date->format('M d, Y') }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $recentDay->work_date->format('l') }}</span>
                            </td>
                            <td class="px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $recentDay->time_in?->format('g:i A') ?? '--' }}</td>
                            <td class="px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">
                                @if ($recentDay->time_out)
                                    {{ $recentDay->time_out->format('g:i A') }}
                                @else
                                    <span class="text-amber-600 dark:text-amber-400">Not clocked out</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 font-medium {{ $late ? 'text-red-600 dark:text-red-400' : 'text-[#64748b] dark:text-ink-400' }}">
                                {{ $late !== null ? ($late ? $late . ' min' : 'On time') : '--' }}
                            </td>
                            <td class="px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">
                                {{ $recentDay->totalBreakMinutes() }} min
                                @if ($recentDay->overBreakMinutes($dayAssignment) > 0)
                                    <span class="text-red-600 dark:text-red-400">(+{{ $recentDay->overBreakMinutes($dayAssignment) }} over)</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center font-medium text-ink-500">
                            Nothing recorded in {{ $viewingMonth->format('F Y') }}.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($monthDays->hasPages())
            <div class="border-t border-ink-200 px-5 py-4 dark:border-white/10">
                {{ $monthDays->links('components.pagination', ['noun' => 'days in ' . $viewingMonth->format('F')]) }}
            </div>
        @endif
    </x-card>
</div>
