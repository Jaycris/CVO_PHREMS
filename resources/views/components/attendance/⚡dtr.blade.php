<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\AppSetting;
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

    /**
     * The day's breaks, as times rather than a total.
     *
     * Times are what anybody actually knows — "she went on break at one and
     * came back at two". Asking for a total makes HR do arithmetic on somebody
     * else's pay, which is how the wrong figure gets typed.
     *
     * A list, because the schedules here carry a lunch and a coffee break. One
     * pair of fields would silently lose the second and hand back time nobody
     * worked.
     *
     * @var list<array{start: string, end: string}>
     */
    public array $editBreaks = [];

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

    public function addBreak(): void
    {
        abort_unless($this->canCorrect(), 403);

        $this->editBreaks[] = ['kind' => '', 'start' => '', 'end' => ''];
    }

    public function removeBreak(int $index): void
    {
        abort_unless($this->canCorrect(), 403);

        unset($this->editBreaks[$index]);

        // Re-indexed, or Livewire sends the array back as an object with gaps
        // in its keys and the next add lands in the wrong place.
        $this->editBreaks = array_values($this->editBreaks);
    }

    /**
     * The DTR has its own page size.
     *
     * A row per employee per day means a fortnight for fifty staff is seven
     * hundred rows, where the employee directory is fifty. Ten at a time is
     * right for one table and useless for this one.
     */
    public function perPage(): int
    {
        return AppSetting::dtrRowsPerPage();
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
        $this->editBreaks = $day->breaks
            ->sortBy('break_start')
            ->map(fn ($break) => [
                'kind' => $break->kind ?? '',
                'start' => $break->break_start->format('H:i'),
                'end' => $break->break_end?->format('H:i') ?? '',
            ])
            ->values()
            ->all();

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
            'editBreaks' => ['array', 'max:8'],
            'editBreaks.*.kind' => ['nullable', 'in:' . implode(',', array_keys(\App\Models\AttendanceBreak::KINDS))],
            'editBreaks.*.start' => ['nullable', 'date_format:H:i'],
            // An end with no start is meaningless, and would otherwise be
            // dropped without a word.
            'editBreaks.*.end' => ['nullable', 'date_format:H:i', 'required_with:editBreaks.*.start'],
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
                // Rows with no start time are blanks somebody added and left,
                // not an instruction. Removing every row clears the day's
                // breaks, which is what an untaken break should look like.
                array_values(array_filter(
                    $this->editBreaks,
                    fn ($break) => filled($break['start'] ?? null),
                )),
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
        $this->reset(['editingDayId', 'editTimeIn', 'editTimeOut', 'editBreaks', 'editReason']);
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
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                <span class="whitespace-nowrap">
                                    {{ $day->totalBreakMinutes() }} min
                                    @if($day->overBreakMinutes() > 0)
                                        <span class="text-red-600 dark:text-red-400">(+{{ $day->overBreakMinutes() }} over)</span>
                                    @endif
                                </span>

                                {{-- The split underneath, because "40 minutes"
                                     and "40 minutes across nine trips" are
                                     different facts about somebody's day. --}}
                                @php
                                    $byKind = $day->breakMinutesByKind();
                                    $counts = $day->breakCountsByKind();
                                @endphp
                                @if ($day->totalBreakMinutes() > 0)
                                    <span class="mt-1 block text-xs font-medium leading-relaxed text-ink-500 dark:text-ink-500">
                                        @foreach (\App\Models\AttendanceBreak::KINDS as $kindKey => $kindLabel)
                                            @continue (($byKind[$kindKey] ?? 0) <= 0)

                                            <span class="mr-2 inline-block whitespace-nowrap">
                                                {{ \Illuminate\Support\Str::of($kindLabel)->before(' Break') }} {{ $byKind[$kindKey] }}m
                                                @if ($counts[$kindKey] > 1)
                                                    <span class="text-amber-700 dark:text-amber-400">&times;{{ $counts[$kindKey] }}</span>
                                                @endif
                                            </span>
                                        @endforeach

                                        @if (($byKind['unlabelled'] ?? 0) > 0)
                                            <span class="mr-2 inline-block whitespace-nowrap">Unlabelled {{ $byKind['unlabelled'] }}m</span>
                                        @endif
                                    </span>
                                @endif
                            </td>
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
            <div class="flex items-center justify-between gap-3">
                <x-label>Breaks</x-label>
                <button type="button" wire:click="addBreak"
                        class="text-sm font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">
                    + Add a break
                </button>
            </div>

            <div class="mt-2 space-y-2">
                @forelse ($editBreaks as $index => $break)
                    <div class="flex flex-wrap items-start gap-2" wire:key="break-row-{{ $index }}">
                        <div>
                            <span class="block text-[11px] font-semibold uppercase tracking-wide text-ink-500 dark:text-ink-400">Kind</span>
                            <x-select wire:model.blur="editBreaks.{{ $index }}.kind" class="!w-44">
                                <option value="">Not recorded</option>
                                @foreach (\App\Models\AttendanceBreak::KINDS as $kindKey => $kindLabel)
                                    <option value="{{ $kindKey }}">{{ $kindLabel }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <span class="block text-[11px] font-semibold uppercase tracking-wide text-ink-500 dark:text-ink-400">Started</span>
                            <x-input wire:model.blur="editBreaks.{{ $index }}.start" type="time" class="!w-36" />
                        </div>
                        <div>
                            <span class="block text-[11px] font-semibold uppercase tracking-wide text-ink-500 dark:text-ink-400">Ended</span>
                            <x-input wire:model.blur="editBreaks.{{ $index }}.end" type="time" class="!w-36" />
                        </div>
                        <button type="button" wire:click="removeBreak({{ $index }})" title="Remove this break"
                                class="mt-5 inline-flex h-10 w-10 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 transition hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300">
                            <x-icon name="trash" class="h-4 w-4" />
                        </button>

                        @error('editBreaks.' . $index . '.start') <p class="w-full text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        @error('editBreaks.' . $index . '.end') <p class="w-full text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @empty
                    <p class="rounded-lg border border-dashed border-ink-200 px-3 py-4 text-sm font-medium text-ink-500 dark:border-white/10 dark:text-ink-400">
                        No break recorded for this day.
                    </p>
                @endforelse
            </div>

            <p class="mt-2 text-xs font-medium text-ink-500 dark:text-ink-400">
                Worked hours are the time between in and out, less these. Remove a row if the break was never
                actually taken. On a night shift, a break after midnight is understood as the following morning.
            </p>
        </div>

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