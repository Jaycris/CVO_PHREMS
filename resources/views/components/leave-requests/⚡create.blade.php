<?php

use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Employee $employee;
    public ?int $leaveTypeId = null;
    public string $startDate = '';
    public string $endDate = '';
    public string $reason = '';
    /** 'whole' or 'half'. Asked before the hours, so the hours only appear when they apply. */
    public string $duration = 'whole';

    /** '', 'first' or 'second' half of their shift. Half a day is always one date. */
    public string $halfDayPeriod = '';

    public function updatedDuration(): void
    {
        if ($this->duration === 'half') {
            // One date, and the first half pre-chosen so the field is never
            // left empty by somebody who thought they had finished.
            $this->endDate = $this->startDate;
            $this->halfDayPeriod = $this->halfDayPeriod ?: 'first';

            return;
        }

        $this->halfDayPeriod = '';
    }

    /**
     * The two halves of the shift they are rostered on for the date chosen,
     * as the clock shows them — 10:00 PM - 2:00 AM rather than "morning",
     * which means nothing on a graveyard shift.
     *
     * @return array<string, string>
     */
    public function halfDayOptions(): array
    {
        $generic = ['first' => 'First half of shift', 'second' => 'Second half of shift'];

        if ($this->startDate === '') {
            return $generic;
        }

        $schedule = $this->employee->scheduleAssignmentForDate($this->startDate)?->workSchedule;

        if (! $schedule) {
            return $generic;
        }

        $clock = fn (string $time) => \Illuminate\Support\Carbon::createFromFormat('H:i', $time)->format('g:i A');

        return collect($schedule->halfShiftWindows())
            ->map(fn (array $window) => $clock($window[0]) . ' - ' . $clock($window[1]))
            ->all();
    }

    public function mount(): void
    {
        $employee = Auth::user()->employee;

        abort_unless($employee, 403, 'No employee profile is linked to your account.');

        $this->employee = $employee;
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
    }

    public function projectedBalance(): ?float
    {
        if (! $this->leaveTypeId) {
            return null;
        }

        return $this->employee->leaveBalance(LeaveType::find($this->leaveTypeId));
    }

    public function submit(LeaveService $leaveService): void
    {
        // Half a day covers one date, so the end date follows the start rather
        // than being another thing to get wrong.
        if ($this->duration === 'half') {
            $this->endDate = $this->startDate;
        } else {
            $this->halfDayPeriod = '';
        }

        $data = $this->validate([
            'leaveTypeId' => ['required', 'exists:leave_types,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'duration' => ['required', 'in:whole,half'],
            'halfDayPeriod' => ['nullable', 'in:,first,second', 'required_if:duration,half'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'halfDayPeriod.required_if' => 'Choose which half of your shift you are taking off.',
        ]);

        $leaveRequest = $leaveService->submit(
            $this->employee,
            LeaveType::findOrFail($data['leaveTypeId']),
            $data['startDate'],
            $data['endDate'],
            $data['reason'] ?: null,
            $data['halfDayPeriod'] ?: null,
        );

        $this->redirect(route('leave-requests.show', $leaveRequest), navigate: true);
    }

    public function with(): array
    {
        return [
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('name')->get(),
        ];
    }
};
?>

<div class="w-full max-w-6xl space-y-7">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Request Leave</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">File a leave request for manager and HR review.</p>
        </div>
        <x-button as="a" href="{{ route('leave-requests.index') }}" wire:navigate variant="secondary" class="h-12 rounded-xl px-5 text-sm">
            Back to Requests
        </x-button>
    </div>

    @if (! $employee->isRegular())
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 dark:border-amber-400/20 dark:bg-amber-500/10 dark:text-amber-300">
            You are {{ $employee->employment_status }} and not yet eligible for paid leave credits. This request will be filed as Leave Without Pay (LWOP).
        </div>
    @endif

    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
        <x-card class="rounded-2xl">
            <form wire:submit="submit" class="space-y-5">
                <div>
                    <x-label>Leave Type</x-label>
                    <x-select wire:model.live="leaveTypeId">
                        <option value="">Select leave type</option>
                        @foreach ($leaveTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }} ({{ $employee->leaveBalance($type) }} days available)</option>
                        @endforeach
                    </x-select>
                    @error('leaveTypeId') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- The hours field appears in the browser, not after a round
                     trip: waiting half a second for a field to appear reads as
                     the form being broken. The value still syncs to the server
                     behind it, which is what the summary and the submit use. --}}
                <div class="grid gap-5 sm:grid-cols-2" x-data="{ duration: $wire.entangle('duration').live }">
                    <div :class="duration === 'half' ? '' : 'sm:col-span-2'">
                        <x-label>Whole day or half day?</x-label>
                        <x-select x-model="duration">
                            <option value="whole">Whole day</option>
                            <option value="half">Half day Leave</option>
                        </x-select>
                        @error('duration') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div x-cloak x-show="duration === 'half'">
                        <x-label>Which hours are you taking off?</x-label>
                        <x-select wire:model.live="halfDayPeriod">
                            @foreach ($this->halfDayOptions() as $value => $hours)
                                <option value="{{ $value }}">{{ $hours }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1.5 text-xs font-medium text-[#778599]">
                            Taken from your own shift for that date. Half a day costs half a leave credit.
                        </p>
                        @error('halfDayPeriod') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2" x-data="{ duration: $wire.entangle('duration').live }">
                    <div :class="duration === 'half' ? 'sm:col-span-2' : ''">
                        <x-label>Start Date</x-label>
                        <div class="relative" x-data="datePicker($wire.entangle('startDate').live)">
                            <button type="button" @click="open = ! open" class="flex h-11 w-full items-center justify-between rounded-lg border border-ink-200 bg-white px-3.5 text-left text-sm font-medium text-ink-700 shadow-sm transition hover:bg-ink-50 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-600/20 dark:border-white/10 dark:bg-ink-900 dark:text-white dark:hover:bg-white/5">
                                <span x-text="display()"></span>
                                <x-icon name="calendar" class="h-4 w-4 text-ink-500" />
                            </button>
                            <div x-cloak x-show="open" @click.outside="open = false" x-transition class="relative z-30 mt-2 w-full rounded-lg border border-ink-200 bg-white p-4 shadow-lg shadow-ink-200/50 dark:border-white/10 dark:bg-ink-900 dark:shadow-black/30">
                                <div class="mb-4 flex items-center justify-between">
                                    <button type="button" @click="previousMonth()" class="rounded-lg p-2 text-ink-500 hover:bg-ink-100 dark:hover:bg-white/10"><x-icon name="chevron-down" class="h-4 w-4 rotate-90" /></button>
                                    <div class="flex items-center gap-1.5"><label class="sr-only">Calendar month</label><select x-model.number="month" aria-label="Calendar month" class="h-9 w-28 rounded-lg border border-ink-200 bg-white px-2 text-sm font-bold text-ink-950 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-950 dark:text-white"><template x-for="(monthName, monthIndex) in monthNames" :key="monthName"><option :value="monthIndex" x-text="monthName"></option></template></select><label class="sr-only">Calendar year</label><select x-model.number="year" aria-label="Calendar year" class="h-9 w-20 rounded-lg border border-ink-200 bg-white px-2 text-sm font-bold text-ink-950 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-950 dark:text-white"><template x-for="yearOption in yearOptions()" :key="yearOption"><option :value="yearOption" x-text="yearOption"></option></template></select></div>
                                    <button type="button" @click="nextMonth()" class="rounded-lg p-2 text-ink-500 hover:bg-ink-100 dark:hover:bg-white/10"><x-icon name="chevron-down" class="h-4 w-4 -rotate-90" /></button>
                                </div>
                                <div class="grid grid-cols-7 gap-1 text-center text-xs font-bold uppercase tracking-wide text-ink-400">
                                    <template x-for="dayName in dayNames" :key="dayName"><div class="py-1" x-text="dayName"></div></template>
                                </div>
                                <div class="mt-1 grid grid-cols-7 gap-1 text-center text-sm">
                                    <template x-for="blank in firstDay()" :key="'blank-' + blank"><div class="h-9"></div></template>
                                    <template x-for="day in daysInMonth()" :key="day">
                                        <button type="button" @click="select(day)" x-text="day" :class="isSelected(day) ? 'bg-brand-700 text-white shadow-sm' : 'text-ink-700 hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-white/10'" class="h-9 rounded-lg font-semibold transition"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        @error('startDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    {{-- A half day is one date, so there is nothing to end. --}}
                    <div x-show="duration !== 'half'">
                        <x-label>End Date</x-label>
                        <div class="relative" x-data="datePicker($wire.entangle('endDate').live)">
                            <button type="button" @click="open = ! open" class="flex h-11 w-full items-center justify-between rounded-lg border border-ink-200 bg-white px-3.5 text-left text-sm font-medium text-ink-700 shadow-sm transition hover:bg-ink-50 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-600/20 dark:border-white/10 dark:bg-ink-900 dark:text-white dark:hover:bg-white/5">
                                <span x-text="display()"></span>
                                <x-icon name="calendar" class="h-4 w-4 text-ink-500" />
                            </button>
                            <div x-cloak x-show="open" @click.outside="open = false" x-transition class="relative z-30 mt-2 w-full rounded-lg border border-ink-200 bg-white p-4 shadow-lg shadow-ink-200/50 dark:border-white/10 dark:bg-ink-900 dark:shadow-black/30">
                                <div class="mb-4 flex items-center justify-between">
                                    <button type="button" @click="previousMonth()" class="rounded-lg p-2 text-ink-500 hover:bg-ink-100 dark:hover:bg-white/10"><x-icon name="chevron-down" class="h-4 w-4 rotate-90" /></button>
                                    <div class="flex items-center gap-1.5"><label class="sr-only">Calendar month</label><select x-model.number="month" aria-label="Calendar month" class="h-9 w-28 rounded-lg border border-ink-200 bg-white px-2 text-sm font-bold text-ink-950 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-950 dark:text-white"><template x-for="(monthName, monthIndex) in monthNames" :key="monthName"><option :value="monthIndex" x-text="monthName"></option></template></select><label class="sr-only">Calendar year</label><select x-model.number="year" aria-label="Calendar year" class="h-9 w-20 rounded-lg border border-ink-200 bg-white px-2 text-sm font-bold text-ink-950 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-950 dark:text-white"><template x-for="yearOption in yearOptions()" :key="yearOption"><option :value="yearOption" x-text="yearOption"></option></template></select></div>
                                    <button type="button" @click="nextMonth()" class="rounded-lg p-2 text-ink-500 hover:bg-ink-100 dark:hover:bg-white/10"><x-icon name="chevron-down" class="h-4 w-4 -rotate-90" /></button>
                                </div>
                                <div class="grid grid-cols-7 gap-1 text-center text-xs font-bold uppercase tracking-wide text-ink-400">
                                    <template x-for="dayName in dayNames" :key="dayName"><div class="py-1" x-text="dayName"></div></template>
                                </div>
                                <div class="mt-1 grid grid-cols-7 gap-1 text-center text-sm">
                                    <template x-for="blank in firstDay()" :key="'blank-' + blank"><div class="h-9"></div></template>
                                    <template x-for="day in daysInMonth()" :key="day">
                                        <button type="button" @click="select(day)" x-text="day" :class="isSelected(day) ? 'bg-brand-700 text-white shadow-sm' : 'text-ink-700 hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-white/10'" class="h-9 rounded-lg font-semibold transition"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        @error('endDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <x-label>Reason (optional)</x-label>
                    <x-textarea wire:model="reason" rows="4" placeholder="Add notes that can help your manager review the request." />
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-ink-100 pt-5 dark:border-white/10 sm:flex-row sm:justify-end">
                    <x-button as="a" href="{{ route('leave-requests.index') }}" wire:navigate variant="secondary" class="sm:min-w-28">Cancel</x-button>
                    <x-button type="submit" class="sm:min-w-36">
                        <span wire:loading.remove wire:target="submit">Submit Request</span>
                        <span wire:loading wire:target="submit">Submitting...</span>
                    </x-button>
                </div>
            </form>
        </x-card>

        <x-card class="rounded-2xl">
            <p class="muted-label">Request Summary</p>
            <div class="mt-4 space-y-4">
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Days Requested</p>
                    <p class="mt-2 text-2xl font-bold text-ink-950 dark:text-white">
                        @if ($duration === 'half')
                            0.5
                        @elseif ($startDate && $endDate)
                            {{ \Carbon\Carbon::parse($startDate)->diffInDays(\Carbon\Carbon::parse($endDate)) + 1 }}
                        @else
                            --
                        @endif
                    </p>
                    @if ($duration === 'half' && $halfDayPeriod)
                        <p class="mt-1 text-xs font-medium text-[#778599]">
                            {{ $this->halfDayOptions()[$halfDayPeriod] ?? '' }} off on {{ $startDate ? \Carbon\Carbon::parse($startDate)->format('M j, Y') : 'the date chosen' }}.
                        </p>
                    @endif
                </div>
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Available Balance</p>
                    <p class="mt-2 text-2xl font-bold text-ink-950 dark:text-white">{{ $leaveTypeId ? $this->projectedBalance() . ' days' : '--' }}</p>
                </div>
            </div>
        </x-card>
    </div>
</div>
