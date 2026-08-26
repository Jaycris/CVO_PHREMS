<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Services\Attendance\AttendanceCorrectionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public string $employeeId = '';
    public string $fromDate = '';
    public string $toDate = '';

    public bool $showEdit = false;

    /** Locked: the row being corrected is chosen by clicking, never posted. */
    #[Locked]
    public ?int $editingDayId = null;

    public string $editTimeIn = '';
    public string $editTimeOut = '';
    public string $editReason = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->fromDate = now()->startOfMonth()->toDateString();
        $this->toDate = now()->toDateString();
    }

    /** Every property here is a filter, so any change starts the list again. */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function canCorrect(): bool
    {
        return auth()->user()?->can('attendance.manage') ?? false;
    }

    public function edit(int $dayId): void
    {
        abort_unless($this->canCorrect(), 403);

        $day = AttendanceDay::findOrFail($dayId);

        $this->editingDayId = $day->id;
        $this->editTimeIn = $day->time_in?->format('H:i') ?? '';
        $this->editTimeOut = $day->time_out?->format('H:i') ?? '';
        $this->editReason = '';

        $this->resetValidation();
        $this->showEdit = true;
    }

    public function save(AttendanceCorrectionService $corrections): void
    {
        abort_unless($this->canCorrect(), 403);

        $this->validate([
            'editTimeIn' => ['nullable', 'date_format:H:i'],
            'editTimeOut' => ['nullable', 'date_format:H:i'],
            // Required, and deliberately so. Six months on, "why is this day
            // different from what I clocked?" needs an answer on the record.
            'editReason' => ['required', 'string', 'min:3', 'max:255'],
        ], attributes: [
            'editTimeIn' => 'time in',
            'editTimeOut' => 'time out',
            'editReason' => 'reason',
        ]);

        $day = AttendanceDay::with('employee')->findOrFail($this->editingDayId);

        try {
            $corrections->apply(
                $day->employee,
                $day->work_date,
                $this->editTimeIn ?: null,
                $this->editTimeOut ?: null,
                $this->editReason,
                auth()->user(),
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The service speaks in its own field names; put them where the
            // form can show them.
            foreach ($e->errors() as $messages) {
                $this->addError('editTimeOut', $messages[0]);
            }

            return;
        }

        $this->showEdit = false;
        $this->statusMessage = 'Attendance for ' . $day->work_date->format('M d, Y')
            . ' updated for ' . ($day->employee->fullName() ?: $day->employee->employee_id) . '.';
    }

    public function closeEdit(): void
    {
        $this->reset(['editingDayId', 'editTimeIn', 'editTimeOut', 'editReason']);
        $this->resetValidation();
        $this->showEdit = false;
    }

    public function with(): array
    {
        // whereDate rather than whereBetween: a DATE column compared against a
        // plain string drops the last day of the range on some drivers.
        $days = AttendanceDay::with(['employee', 'breaks'])
            ->whereDate('work_date', '>=', $this->fromDate)
            ->whereDate('work_date', '<=', $this->toDate)
            ->when($this->employeeId, fn ($q) => $q->where('employee_id', $this->employeeId))
            ->orderByDesc('work_date')
            ->paginate($this->perPage());

        /*
         * The runs that have closed over this range, fetched once rather than
         * asked per row. A day inside one of them cannot be corrected, and the
         * button is hidden rather than left to fail on save.
         */
        $settled = \App\Models\PayrollRun::query()
            ->settledOver($this->fromDate, $this->toDate)
            ->get(['period_start', 'period_end']);

        // How many times each day on this page has already been corrected, so
        // an edited record is visibly an edited record.
        $correctionCounts = AttendanceCorrection::query()
            ->whereIn('attendance_day_id', $days->getCollection()->pluck('id'))
            ->selectRaw('attendance_day_id, count(*) as total')
            ->groupBy('attendance_day_id')
            ->pluck('total', 'attendance_day_id');

        return [
            'employees' => Employee::orderBy('employee_id')->get(),
            'days' => $days,
            'canCorrect' => $this->canCorrect(),
            'settledRuns' => $settled,
            'correctionCounts' => $correctionCounts,
        ];
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Daily Time Record</h1>

    @if ($statusMessage)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $statusMessage }}
        </div>
    @endif

    <x-card class="flex flex-wrap items-end gap-3">
        <div>
            <x-label>Employee</x-label>
            <x-select wire:model.live="employeeId">
                <option value="">All employees</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}">{{ $employee->employee_id }} — {{ $employee->fullName() ?: $employee->company_email }}</option>
                @endforeach
            </x-select>
        </div>
        <div>
            <x-label>From</x-label>
            <x-input wire:model.live="fromDate" type="date" />
        </div>
        <div>
            <x-label>To</x-label>
            <x-input wire:model.live="toDate" type="date" />
        </div>
    </x-card>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Time In</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Time Out</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Late</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Break</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Worked</th>
                        @if ($canCorrect)
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Correct</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($days as $day)
                        <tr wire:key="dtr-{{ $day->id }}">
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $day->work_date->format('M d, Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->employee->employee_id }} — {{ $day->employee->fullName() ?: $day->employee->company_email }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->time_in?->format('g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->time_out?->format('g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->lateMinutes() !== null ? $day->lateMinutes() . ' min' : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->totalBreakMinutes() }} min @if($day->overBreakMinutes() > 0) <span class="text-red-600 dark:text-red-400">(+{{ $day->overBreakMinutes() }} over)</span> @endif</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                {{ $day->totalWorkedMinutes() !== null ? number_format($day->totalWorkedMinutes() / 60, 1) . ' hrs' : '—' }}
                                @if (($correctionCounts[$day->id] ?? 0) > 0)
                                    <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                                          title="This day has been corrected by an administrator">Edited</span>
                                @endif
                            </td>
                            @if ($canCorrect)
                                @php
                                    // Inside a finalised or paid run, so the figures are settled.
                                    $settled = $settledRuns->contains(fn ($run) => $day->work_date->betweenIncluded($run->period_start, $run->period_end));
                                @endphp
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if ($settled)
                                        <span class="text-xs font-semibold text-[#778599]" title="This date sits in a payroll run that is already finalised. Correct it on the next run as an adjustment.">Paid — locked</span>
                                    @else
                                        <button type="button" wire:click="edit({{ $day->id }})"
                                                class="inline-flex h-8 items-center rounded-lg border border-ink-200 bg-white px-3 text-xs font-bold text-ink-700 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 dark:border-white/10 dark:bg-white/5 dark:text-ink-200">
                                            Edit
                                        </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canCorrect ? 8 : 7 }}" class="px-4 py-8 text-center font-medium text-[#778599]">No attendance records for this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($days->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $days->links('components.pagination', ['noun' => 'days']) }}
            </div>
        @endif
    </x-card>

    <x-modal :show="$showEdit" onClose="closeEdit" maxWidth="lg">
        @php $editing = $editingDayId ? \App\Models\AttendanceDay::with('employee')->find($editingDayId) : null; @endphp

        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Correct attendance</p>
            <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">
                {{ $editing?->employee->fullName() ?: $editing?->employee->employee_id }}
            </h2>
            <p class="mt-1 text-sm font-medium text-ink-600 dark:text-ink-300">
                {{ $editing?->work_date->format('l, M d, Y') }}
            </p>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div>
                <x-label>Time In</x-label>
                <x-input wire:model.blur="editTimeIn" type="time" />
                @error('editTimeIn') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-label>Time Out</x-label>
                <x-input wire:model.blur="editTimeOut" type="time" />
                @error('editTimeOut') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <p class="mt-2 text-xs font-medium text-ink-500 dark:text-ink-400">
            Leave Time Out empty to reopen the day, so the employee can carry on punching.
            A time out earlier than the time in is treated as a shift running past midnight.
        </p>

        <div class="mt-5">
            <x-label>Reason</x-label>
            {{-- .blur so the reason has reached the server by the time Save is pressed. --}}
            <x-input wire:model.blur="editReason" type="text" placeholder="e.g. Punched out by mistake at 5:34 AM" />
            @error('editReason') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <p class="mt-1.5 text-xs font-medium text-ink-500 dark:text-ink-400">
                Recorded against your name. This is what answers the question if the employee ever queries their pay.
            </p>
        </div>

        {{--
            type="button" on both. x-button defaults to submit, and a submit
            button inside a modal will try to post whatever form encloses it
            instead of reaching Livewire.
        --}}
        <div class="mt-7 flex items-center justify-end gap-3">
            <x-button type="button" wire:click="closeEdit" variant="secondary">Cancel</x-button>
            <x-button type="button" wire:click="save">
                <span wire:loading.remove wire:target="save">Save correction</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-button>
        </div>
    </x-modal>
</div>