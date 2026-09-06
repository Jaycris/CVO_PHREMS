<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Employee;
use App\Models\OffsiteAssignment;
use App\Notifications\OffsiteWorkScheduled;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Days staff worked for the company away from the punch clock.
 *
 * Built around the event rather than the person: the company goes to a trade
 * exhibit from the 8th to the 13th and six people are on the booth, so the
 * dates and the reason are typed once and everybody going is ticked. Recorded
 * per employee underneath, so one person can be taken off without unpicking the
 * rest.
 *
 * Nobody clocks in from a stand. Without this every one of those days reads as
 * an absence and takes a day's pay from people who were working.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $startDate = '';
    public string $endDate = '';
    public string $reason = '';

    /** @var list<int> */
    public array $employeeIds = [];

    public string $search = '';
    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->startDate = now('Asia/Manila')->toDateString();
        $this->endDate = now('Asia/Manila')->toDateString();
    }

    public function create(): void
    {
        $this->reset(['editingId', 'reason', 'employeeIds']);
        $this->startDate = now('Asia/Manila')->toDateString();
        $this->endDate = now('Asia/Manila')->toDateString();

        $this->resetValidation();
        $this->showForm = true;
    }

    /**
     * Opens one person's dates, not the whole event.
     *
     * Editing here changes that employee alone, which is the point of storing
     * them per person — somebody pulled off the booth on the last day should
     * not mean retyping the other five people.
     */
    public function edit(int $id): void
    {
        $assignment = OffsiteAssignment::findOrFail($id);

        $this->editingId = $assignment->id;
        $this->startDate = $assignment->start_date->toDateString();
        $this->endDate = $assignment->end_date->toDateString();
        $this->reason = $assignment->reason;
        $this->employeeIds = [$assignment->employee_id];

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'reason' => ['required', 'string', 'max:150'],
            'employeeIds' => ['required', 'array', 'min:1'],
            'employeeIds.*' => ['integer', 'exists:employees,id'],
        ], [
            'employeeIds.required' => 'Tick at least one employee.',
            'endDate.after_or_equal' => 'The last day cannot be before the first.',
        ], [
            'startDate' => 'first day',
            'endDate' => 'last day',
            'reason' => 'reason',
        ]);

        if ($this->editingId) {
            $assignment = OffsiteAssignment::findOrFail($this->editingId);

            $assignment->update([
                'employee_id' => $data['employeeIds'][0],
                'start_date' => $data['startDate'],
                'end_date' => $data['endDate'],
                'reason' => $data['reason'],
            ]);

            $this->tell($assignment->fresh(), OffsiteWorkScheduled::CHANGED);

            $this->statusMessage = 'Updated. The employee has been told.';
        } else {
            foreach ($data['employeeIds'] as $employeeId) {
                $assignment = OffsiteAssignment::create([
                    'employee_id' => $employeeId,
                    'start_date' => $data['startDate'],
                    'end_date' => $data['endDate'],
                    'reason' => $data['reason'],
                    'created_by_user_id' => auth()->id(),
                ]);

                $this->tell($assignment, OffsiteWorkScheduled::ADDED);
            }

            $days = Carbon::parse($data['startDate'])->diffInDays(Carbon::parse($data['endDate'])) + 1;
            $people = count($data['employeeIds']);

            $this->statusMessage = $people . ' ' . str('employee')->plural($people)
                . ' marked as working off-site for ' . $days . ' ' . str('day')->plural($days)
                . ', and told they need not clock in.';
        }

        $this->closeForm();
        $this->resetPage();
    }

    public function closeForm(): void
    {
        $this->reset(['editingId', 'reason', 'employeeIds']);
        $this->resetValidation();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $assignment = OffsiteAssignment::with('employee')->findOrFail($id);

        // Told before the row goes, because the notification reads the dates
        // off it — and this is the message that matters most. Somebody who was
        // told not to clock in, and is then taken off the list without being
        // told, loses a day's pay for following the last thing they heard.
        $this->tell($assignment, OffsiteWorkScheduled::REMOVED);

        $assignment->delete();

        $this->statusMessage = 'Removed. The employee has been told they need to clock in on those days.';
        $this->resetPage();
    }

    /**
     * Lets the employee know, if there is anybody to tell.
     *
     * Somebody with no login has no inbox here and no email on file worth
     * notifying; HR tells them in person, and a failure to notify must not
     * stop the record being made.
     */
    protected function tell(OffsiteAssignment $assignment, string $change): void
    {
        $user = $assignment->employee?->user;

        if (! $user) {
            return;
        }

        try {
            $user->notify(new OffsiteWorkScheduled($assignment, $change));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function with(): array
    {
        return [
            'assignments' => OffsiteAssignment::with(['employee', 'createdBy'])
                ->when($this->search, fn ($q) => $q
                    ->where('reason', 'like', "%{$this->search}%")
                    ->orWhereHas('employee', fn ($e) => $e
                        ->where('employee_id', 'like', "%{$this->search}%")
                        ->orWhere('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%")))
                ->orderByDesc('start_date')
                ->paginate($this->perPage()),
            // Separated staff are left out: they cannot be sent anywhere.
            'employees' => Employee::whereNull('separation_date')
                ->orderBy('employee_id')
                ->get(),
        ];
    }
};
?>

<div class="space-y-7">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="max-w-3xl">
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Off-Site Work</h1>
            <p class="mt-2 text-sm font-medium leading-6 text-ink-600 dark:text-ink-300">
                Record approved work completed away from the office. These dates remain paid workdays and are excluded from absence tracking.
            </p>
        </div>

        <x-button wire:click="create" @click="$dispatch('open-phrems-modal', 'showForm')" class="h-11 shrink-0 px-5">
            <x-icon name="plus" class="h-4 w-4" />
            Add Off-Site Days
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $statusMessage }}
        </div>
    @endif

    <x-card :padding="false" class="directory-panel" x-data="{ selected: [], deleteOpen: false }">
        <div class="directory-toolbar">
            <div>
                <h2 class="directory-title">Off-Site Work Directory</h2>
                <p class="directory-selection" x-text="selected.length ? selected.length + ' selected' : ''"></p>
            </div>

            <div class="directory-toolbar-actions">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-on:click="if (selected.length === 1) $wire.edit(Number(selected[0]))"
                        x-bind:disabled="selected.length !== 1"
                        x-bind:title="selected.length === 1 ? 'Edit selected record' : 'Select one record to edit'"
                        x-bind:class="selected.length === 1 ? 'text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                    >
                        <x-icon name="pencil" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        x-on:click="if (selected.length === 1) deleteOpen = true"
                        x-bind:disabled="selected.length !== 1"
                        x-bind:title="selected.length === 1 ? 'Remove selected record' : 'Select one record to remove'"
                        x-bind:class="selected.length === 1 ? 'border-red-200 bg-red-50 text-red-600 hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                    >
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>

                <label class="directory-search">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                    <input
                        type="text"
                        wire:model.live.debounce.250ms="search"
                        x-on:input="selected = []"
                        placeholder="Search off-site work..."
                        class="block h-10 w-full rounded-lg border border-ink-200 bg-white pl-9 pr-3.5 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-400 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white"
                    >
                </label>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="directory-table">
                <thead class="directory-table-head">
                    <tr>
                        <th class="w-14 px-6 py-4 text-left">
                            <input
                                type="checkbox"
                                class="directory-checkbox"
                                x-bind:checked="selected.length === {{ $assignments->count() }} && {{ $assignments->count() }} > 0"
                                x-on:click="selected = selected.length === {{ $assignments->count() }} ? [] : [{{ $assignments->getCollection()->pluck('id')->implode(',') }}].map(String)"
                            >
                        </th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Employee</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Dates</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Days</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Reason</th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Added By</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($assignments as $assignment)
                        <tr
                            wire:key="offsite-{{ $assignment->id }}"
                            tabindex="0"
                            x-bind:aria-selected="selected.includes('{{ $assignment->id }}')"
                            x-on:click="selected = selected.includes('{{ $assignment->id }}') ? selected.filter(id => id !== '{{ $assignment->id }}') : [...selected, '{{ $assignment->id }}']"
                            x-on:keydown.enter.prevent="selected = selected.includes('{{ $assignment->id }}') ? selected.filter(id => id !== '{{ $assignment->id }}') : [...selected, '{{ $assignment->id }}']"
                            x-on:keydown.space.prevent="selected = selected.includes('{{ $assignment->id }}') ? selected.filter(id => id !== '{{ $assignment->id }}') : [...selected, '{{ $assignment->id }}']"
                            class="directory-row cursor-pointer outline-none transition focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500"
                            x-bind:class="selected.includes('{{ $assignment->id }}') ? 'bg-brand-50/60 dark:bg-brand-900/20' : ''"
                        >
                            <td class="px-6 py-4">
                                <input type="checkbox" value="{{ $assignment->id }}" x-model="selected" x-on:click.stop class="directory-checkbox">
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <x-avatar :employee="$assignment->employee" size="md" />
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-ink-950 dark:text-white">{{ $assignment->employee?->fullName() ?: $assignment->employee?->employee_id }}</p>
                                        <p class="mt-0.5 text-xs font-medium text-ink-500">{{ $assignment->employee?->employee_id }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-ink-700 dark:text-ink-200">{{ $assignment->rangeLabel() }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $assignment->dayCount() }}</td>
                            <td class="min-w-64 px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $assignment->reason }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-ink-500">{{ $assignment->createdBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="building" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No off-site work recorded</p>
                                <p class="mt-1 text-sm font-medium text-ink-500">
                                    Add the dates of a client visit, exhibit, or other approved assignment.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($assignments->hasPages())
            <div class="directory-pagination" x-on:click="selected = []">
                {{ $assignments->links('components.pagination', ['noun' => 'records']) }}
            </div>
        @endif

        <div x-cloak x-show="deleteOpen" x-transition.opacity class="fixed inset-0 z-[110] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm" x-on:click="deleteOpen = false"></div>
            <div x-show="deleteOpen" x-transition class="professional-panel relative z-10 w-full max-w-md p-6 shadow-2xl">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-300">
                        <x-icon name="trash" class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-red-600 dark:text-red-300">Remove record</p>
                        <h3 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Remove these off-site days?</h3>
                        <p class="mt-2 text-sm font-medium leading-6 text-ink-600 dark:text-ink-300">These dates will return to attendance tracking and may count as absences without a time record.</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3 border-t border-ink-100 pt-5 dark:border-white/10">
                    <x-button type="button" variant="secondary" x-on:click="deleteOpen = false">Cancel</x-button>
                    <button type="button" x-on:click="$wire.delete(Number(selected[0])); selected = []; deleteOpen = false" class="inline-flex h-10 items-center justify-center rounded-lg bg-red-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">Remove</button>
                </div>
            </div>
        </div>
    </x-card>

    <x-modal :show="$showForm" onClose="closeForm" maxWidth="4xl">
        <div class="flex items-start justify-between gap-4 border-b border-ink-100 pb-5 dark:border-white/10">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                    <x-icon name="building" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Off-site work</p>
                    <h2 class="mt-1 text-2xl font-bold text-ink-950 dark:text-white">
                        {{ $editingId ? 'Edit off-site assignment' : 'Add off-site assignment' }}
                    </h2>
                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Choose the covered dates, reason, and employees who worked away from the office.</p>
                </div>
            </div>
            <button type="button" wire:click="closeForm" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-white/10 dark:hover:text-white" title="Close">
                <x-icon name="x-mark" class="h-5 w-5" />
            </button>
        </div>

        <div class="mt-5 rounded-xl border border-ink-200 bg-ink-50/70 p-4 dark:border-white/10 dark:bg-white/[0.03]">
            <p class="muted-label">Assignment dates</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>First Day</x-label>
                    <x-date-picker model="startDate" />
                    @error('startDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Last Day</x-label>
                    <x-date-picker model="endDate" align="right" />
                    @error('endDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="mt-3 text-xs font-medium leading-5 text-ink-500 dark:text-ink-400">
                Both days are included. Rest days inside the range stay rest days, so nobody is paid twice.
            </p>
        </div>

        <div class="mt-5">
            <x-label>Reason</x-label>
            <x-input wire:model.blur="reason" type="text" placeholder="e.g. Trade exhibit — booth duty" />
            @error('reason') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <p class="mt-1.5 text-xs font-medium text-ink-500 dark:text-ink-400">
                Shown against the day, so "why was this paid with no time in" has an answer on file.
            </p>
        </div>

        <div class="mt-5">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <x-label>{{ $editingId ? 'Employee' : 'Employees present' }}</x-label>
                    <p class="mt-1 text-xs font-medium text-ink-500 dark:text-ink-400">
                        {{ $editingId ? 'This assignment belongs to one employee.' : 'Select everyone covered by this assignment.' }}
                    </p>
                </div>
                @if (! $editingId)
                    <span class="text-xs font-bold text-brand-700 dark:text-brand-300">{{ count($employeeIds) }} selected</span>
                @endif
            </div>

            <div class="mt-3 grid max-h-72 gap-px overflow-y-auto rounded-xl border border-ink-200 bg-ink-100 sm:grid-cols-2 dark:border-white/10 dark:bg-white/10">
                @foreach ($employees as $employee)
                    <label wire:key="pick-{{ $employee->id }}"
                           class="flex min-h-16 cursor-pointer items-center gap-3 bg-white px-4 py-3 transition hover:bg-brand-50/60 dark:bg-ink-900 dark:hover:bg-brand-500/10">
                        <input type="checkbox" value="{{ $employee->id }}" wire:model="employeeIds"
                               class="directory-checkbox shrink-0">
                        <x-avatar :employee="$employee" size="sm" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-bold text-ink-900 dark:text-white">{{ $employee->fullName() ?: $employee->employee_id }}</span>
                            <span class="block text-xs font-medium text-ink-500">{{ $employee->employee_id }} · {{ $employee->department?->name ?? 'No department' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @error('employeeIds') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="mt-7 flex items-center justify-end gap-3">
            <x-button type="button" wire:click="closeForm" variant="secondary">Cancel</x-button>
            <x-button type="button" wire:click="save">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-button>
        </div>
    </x-modal>
</div>
