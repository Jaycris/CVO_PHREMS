<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\WorkSchedule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;
    public string $name = '';
    public string $start_time = '';
    public string $end_time = '';
    public string $lunch_break_minutes = '60';
    public string $coffee_break_minutes = '30';
    /** @var list<int> ISO-8601 day numbers, 1 = Mon .. 7 = Sun */
    public array $work_days = [1, 2, 3, 4, 5];
    /** 'auto' defers to whether the shift overlaps the 22:00-06:00 window. */
    public string $night_differential = 'auto';
    public ?int $editingId = null;
    public string $search = '';
    public bool $showDelete = false;
    /** @var list<int> */
    public array $deleteIds = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }
    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:work_schedules,name,' . $this->editingId],
            'start_time' => ['required'],
            'end_time' => ['required'],
            'lunch_break_minutes' => ['required', 'integer', 'min:0'],
            'coffee_break_minutes' => ['required', 'integer', 'min:0'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7'],
            'night_differential' => ['required', 'in:auto,yes,no'],
        ]);

        $data['work_days'] = array_values(array_map('intval', $data['work_days']));
        sort($data['work_days']);

        $data['night_differential_eligible'] = match ($data['night_differential']) {
            'yes' => true,
            'no' => false,
            default => null,
        };
        unset($data['night_differential']);

        WorkSchedule::updateOrCreate(['id' => $this->editingId], $data);

        $this->resetForm();
        $this->showForm = false;
    }

    public function edit(int $id): void
    {
        $schedule = WorkSchedule::findOrFail($id);
        $this->editingId = $schedule->id;
        $this->name = $schedule->name;
        $this->start_time = $schedule->start_time->format('H:i');
        $this->end_time = $schedule->end_time->format('H:i');
        $this->lunch_break_minutes = (string) $schedule->lunch_break_minutes;
        $this->coffee_break_minutes = (string) $schedule->coffee_break_minutes;
        $this->work_days = $schedule->workDays();
        $this->night_differential = match ($schedule->night_differential_eligible) {
            true => 'yes',
            false => 'no',
            default => 'auto',
        };
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'start_time', 'end_time', 'editingId']);
        $this->lunch_break_minutes = '60';
        $this->coffee_break_minutes = '30';
        $this->work_days = [1, 2, 3, 4, 5];
        $this->night_differential = 'auto';
    }

    public function prepareDelete(array $ids): void
    {
        $this->deleteIds = WorkSchedule::whereIn('id', array_map('intval', $ids))->pluck('id')->all();
        $this->showDelete = $this->deleteIds !== [];
    }

    public function deleteSelected(): void
    {
        WorkSchedule::whereIn('id', $this->deleteIds)->delete();
        $this->deleteIds = [];
        $this->showDelete = false;
        $this->dispatch('schedules-deleted');
    }

    public function delete(int $id): void
    {
        WorkSchedule::findOrFail($id)->delete();
    }

    public function with(): array
    {
        $query = WorkSchedule::query()
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%' . $this->search . '%'))
            ->orderBy('name');

        return [
            'schedules' => $query->paginate($this->perPage()),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-ink-950 dark:text-white">Work Schedules</h1>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Manage standard shifts, break allowances, workdays, and night differential eligibility.</p>
        </div>
        <x-button type="button" wire:click="create" @click="$dispatch('open-phrems-modal', 'showForm')">
            <x-icon name="plus" class="h-4 w-4" /> Add Schedule
        </x-button>
    </div>

    <x-card
        :padding="false"
        class="directory-panel"
        x-data="{ selected: [] }"
        @schedules-deleted.window="selected = []"
    >
        <div class="directory-toolbar">
            <div>
                <h2 class="directory-title">Work Schedule Directory</h2>
                <p x-show="selected.length > 0" x-cloak class="mt-1 text-xs font-semibold text-amber-600" x-text="`${selected.length} selected`"></p>
            </div>

            <div class="directory-toolbar-actions">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-on:click="if (selected.length === 1) { $dispatch('open-phrems-modal', 'showForm'); $wire.edit(selected[0]); }"
                        x-bind:disabled="selected.length !== 1"
                        x-bind:title="selected.length === 1 ? 'Edit selected schedule' : 'Select one schedule to edit'"
                        x-bind:class="selected.length === 1 ? 'text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                    >
                        <x-icon name="pencil" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        x-on:click="if (selected.length > 0) { $dispatch('open-phrems-modal', 'showDelete'); $wire.prepareDelete(selected); }"
                        x-bind:disabled="selected.length === 0"
                        x-bind:title="selected.length > 0 ? 'Delete selected schedules' : 'Select schedules to delete'"
                        x-bind:class="selected.length > 0 ? 'border-red-200 bg-red-50 text-red-600 hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                    >
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>

                <label class="directory-search">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        @input="selected = []"
                        placeholder="Search schedules..."
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
                                x-bind:checked="selected.length === {{ $schedules->count() }} && {{ $schedules->count() }} > 0"
                                @click="selected = (selected.length === {{ $schedules->count() }}) ? [] : [{{ $schedules->getCollection()->pluck('id')->implode(',') }}].map(String)"
                            >
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Name</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Hours</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Lunch</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Coffee</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Work Days</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Night Diff</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($schedules as $schedule)
                        <tr
                            wire:key="sched-{{ $schedule->id }}"
                            @click="selected = selected.includes('{{ $schedule->id }}') ? selected.filter(id => id !== '{{ $schedule->id }}') : [...selected, '{{ $schedule->id }}']"
                            class="directory-row cursor-pointer"
                            x-bind:class="selected.includes('{{ $schedule->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''"
                        >
                            <td class="px-6 py-4" @click.stop>
                                <input type="checkbox" value="{{ $schedule->id }}" x-model="selected" class="directory-checkbox">
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 font-bold text-ink-800 dark:text-white">{{ $schedule->name }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $schedule->start_time->format('g:i A') }} – {{ $schedule->end_time->format('g:i A') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $schedule->lunch_break_minutes }} min</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $schedule->coffee_break_minutes }} min</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">
                                @php $dayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; @endphp
                                {{ collect($schedule->workDays())->map(fn ($d) => $dayLabels[$d] ?? $d)->join(', ') }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$schedule->qualifiesForNightDifferential() ? 'blue' : 'neutral'">
                                    {{ $schedule->qualifiesForNightDifferential() ? 'Yes' : 'No' }}{{ $schedule->night_differential_eligible === null ? ' (auto)' : '' }}
                                </x-badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="calendar" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No schedules yet</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Add the first schedule to define standard working hours.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($schedules->hasPages())
            <div class="directory-pagination" @click="selected = []">
                {{ $schedules->links('components.pagination', ['noun' => 'schedules']) }}
            </div>
        @endif
    </x-card>

    <x-modal wire="showForm" onClose="closeForm">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">{{ $editingId ? 'Edit Schedule' : 'Add Schedule' }}</h2>
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-label>Name</x-label>
                <x-input wire:model="name" type="text" placeholder="e.g. Graveyard" />
                @error('name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-label>Start Time</x-label>
                    <x-input wire:model="start_time" type="time" />
                    @error('start_time') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>End Time</x-label>
                    <x-input wire:model="end_time" type="time" />
                    @error('end_time') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-label>Lunch Break (min)</x-label>
                    <x-input wire:model="lunch_break_minutes" type="number" />
                </div>
                <div>
                    <x-label>Coffee Break (min)</x-label>
                    <x-input wire:model="coffee_break_minutes" type="number" />
                </div>
            </div>

            <div>
                <x-label>Work Days</x-label>
                <p class="mb-2 text-xs font-medium text-[#778599]">Unchecked days are rest days and are never counted as absences.</p>
                <div class="flex flex-wrap gap-3">
                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $value => $label)
                        <label class="flex items-center gap-1.5 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                            <input type="checkbox" wire:model="work_days" value="{{ $value }}"
                                   class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('work_days') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Night Differential</x-label>
                <x-select wire:model="night_differential">
                    <option value="auto">Auto — based on 10PM–6AM overlap</option>
                    <option value="yes">Always eligible</option>
                    <option value="no">Never eligible</option>
                </x-select>
                @error('night_differential') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">{{ $editingId ? 'Update' : 'Add' }}</x-button>
                <x-button type="button" variant="secondary" wire:click="closeForm">Cancel</x-button>
            </div>
        </form>
    </x-modal>
    <x-modal wire="showDelete" onClose="$set('showDelete', false)" maxWidth="lg">
        <div class="flex items-start gap-4">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-600 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300">
                <x-icon name="trash" class="h-5 w-5" />
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-red-600 dark:text-red-300">Delete Confirmation</p>
                <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Delete selected schedules?</h2>
                <p class="mt-2 text-sm font-medium leading-6 text-ink-600 dark:text-ink-300">
                    {{ count($deleteIds) }} schedule(s) will be permanently removed. This action cannot be undone.
                </p>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2 border-t border-ink-100 pt-4 dark:border-white/10">
            <x-button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-button>
            <x-button wire:click="deleteSelected" wire:loading.attr="disabled" wire:target="deleteSelected" variant="danger" class="min-w-32">
                <span wire:loading.remove wire:target="deleteSelected">Delete Schedules</span>
                <span wire:loading wire:target="deleteSelected">Deleting...</span>
            </x-button>
        </div>
    </x-modal>
</div>
