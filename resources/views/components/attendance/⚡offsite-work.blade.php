<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Employee;
use App\Models\OffsiteAssignment;
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
            OffsiteAssignment::findOrFail($this->editingId)->update([
                'employee_id' => $data['employeeIds'][0],
                'start_date' => $data['startDate'],
                'end_date' => $data['endDate'],
                'reason' => $data['reason'],
            ]);

            $this->statusMessage = 'Updated.';
        } else {
            foreach ($data['employeeIds'] as $employeeId) {
                OffsiteAssignment::create([
                    'employee_id' => $employeeId,
                    'start_date' => $data['startDate'],
                    'end_date' => $data['endDate'],
                    'reason' => $data['reason'],
                    'created_by_user_id' => auth()->id(),
                ]);
            }

            $days = Carbon::parse($data['startDate'])->diffInDays(Carbon::parse($data['endDate'])) + 1;
            $people = count($data['employeeIds']);

            $this->statusMessage = $people . ' ' . str('employee')->plural($people)
                . ' marked as working off-site for ' . $days . ' ' . str('day')->plural($days) . '.';
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
        OffsiteAssignment::findOrFail($id)->delete();

        $this->statusMessage = 'Removed. Those days go back to needing a time in.';
        $this->resetPage();
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

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-ink-950 dark:text-white">Off-Site Work</h1>
        <p class="mt-1 text-sm font-medium text-ink-600 dark:text-ink-300">
            Days staff worked for the company away from the clock — an exhibit, a client visit, a booth.
            Those days are paid as normal working days and nobody is marked absent for them.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $statusMessage }}
        </div>
    @endif

    <x-card class="flex flex-wrap items-center justify-between gap-3">
        <label class="relative min-w-64 flex-1">
            <x-input wire:model.live.debounce.250ms="search" placeholder="Search by name or reason..." class="h-10" />
        </label>

        <x-button wire:click="create" @click="$dispatch('open-phrems-modal', 'showForm')" class="h-10 px-4">
            + Add Off-Site Days
        </x-button>
    </x-card>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                <thead class="bg-ink-50 dark:bg-white/[0.03]">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Employee</th>
                        <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Dates</th>
                        <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Days</th>
                        <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Reason</th>
                        <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Added By</th>
                        <th class="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                    @forelse ($assignments as $assignment)
                        <tr wire:key="offsite-{{ $assignment->id }}">
                            <td class="px-5 py-4">
                                <p class="font-semibold text-ink-950 dark:text-white">
                                    {{ $assignment->employee?->fullName() ?: $assignment->employee?->employee_id }}
                                </p>
                                <p class="mt-0.5 text-xs font-medium text-ink-500">{{ $assignment->employee?->employee_id }}</p>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 font-medium text-ink-700 dark:text-ink-200">{{ $assignment->rangeLabel() }}</td>
                            <td class="whitespace-nowrap px-5 py-4 font-medium text-ink-700 dark:text-ink-200">{{ $assignment->dayCount() }}</td>
                            <td class="px-5 py-4 font-medium text-ink-700 dark:text-ink-200">{{ $assignment->reason }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-xs font-medium text-ink-500">{{ $assignment->createdBy?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button wire:click="edit({{ $assignment->id }})" @click="$dispatch('open-phrems-modal', 'showForm')"
                                              type="button" variant="secondary" class="h-9 px-3 text-xs">Edit</x-button>
                                    <x-button wire:click="delete({{ $assignment->id }})"
                                              wire:confirm="Remove these days? They will go back to needing a time in, and will count as absences without one."
                                              type="button" variant="secondary" class="h-9 px-3 text-xs">Remove</x-button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16 text-center">
                                <p class="font-semibold text-ink-700 dark:text-ink-200">Nothing recorded yet.</p>
                                <p class="mt-1 text-sm font-medium text-ink-500">
                                    Add the dates of an exhibit or a client visit, and tick everybody who was there.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($assignments->hasPages())
            <div class="border-t border-ink-200 px-5 py-4 dark:border-white/10">
                {{ $assignments->links('components.pagination', ['noun' => 'records']) }}
            </div>
        @endif
    </x-card>

    <x-modal :show="$showForm" onClose="closeForm" maxWidth="2xl">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Off-site work</p>
            <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">
                {{ $editingId ? 'Edit these days' : 'Which days, and who was there' }}
            </h2>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <x-label>First Day</x-label>
                <x-input wire:model.blur="startDate" type="date" />
                @error('startDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-label>Last Day</x-label>
                <x-input wire:model.blur="endDate" type="date" />
                @error('endDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <p class="mt-2 text-xs font-medium text-ink-500 dark:text-ink-400">
            Both days included. Rest days inside the range stay rest days — nobody is paid twice for a Sunday.
        </p>

        <div class="mt-5">
            <x-label>Reason</x-label>
            <x-input wire:model.blur="reason" type="text" placeholder="e.g. Trade exhibit — booth duty" />
            @error('reason') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <p class="mt-1.5 text-xs font-medium text-ink-500 dark:text-ink-400">
                Shown against the day, so "why was this paid with no time in" has an answer on file.
            </p>
        </div>

        <div class="mt-5">
            <x-label>{{ $editingId ? 'Employee' : 'Who was there' }}</x-label>

            <div class="mt-1 max-h-64 overflow-y-auto rounded-xl border border-ink-200 dark:border-white/10">
                @foreach ($employees as $employee)
                    <label wire:key="pick-{{ $employee->id }}"
                           class="flex cursor-pointer items-center gap-3 border-b border-ink-100 px-4 py-2.5 last:border-0 hover:bg-ink-50 dark:border-white/5 dark:hover:bg-white/5">
                        <input type="checkbox" value="{{ $employee->id }}" wire:model="employeeIds"
                               class="rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-ink-600 dark:bg-ink-800">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-ink-900 dark:text-white">{{ $employee->fullName() ?: $employee->employee_id }}</span>
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
