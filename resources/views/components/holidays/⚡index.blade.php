<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The yearly holiday list payroll reads.
 *
 * Without a row here, a holiday is just a scheduled workday nobody clocked in
 * for — which payroll counts as an absence and deducts a day's pay for. Adding
 * the date is the whole fix.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public int $year;

    public bool $showForm = false;
    public ?int $editingId = null;
    public ?string $statusMessage = null;
    public string $search = '';
    public bool $showDelete = false;
    /** @var list<int> */
    public array $deleteIds = [];

    public string $whose = 'all';

    public string $date = '';
    public string $name = '';
    public string $type = Holiday::REGULAR;
    public string $observance = Holiday::PHILIPPINES;
    public string $note = '';

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function showOnly(string $whose): void
    {
        $this->whose = array_key_exists($whose, Holiday::OBSERVANCES) ? $whose : 'all';

        $this->resetPage();
    }

    /**
     * Only Philippine holidays carry Labor Code categories, so switching whose
     * holiday it is has to drop a type that no longer applies — otherwise the
     * Fourth of July gets saved as a Regular Holiday and somebody later expects
     * the 200% premium that goes with one.
     */
    public function updatedObservance(): void
    {
        $allowed = array_keys(Holiday::typesFor($this->observance));

        if (! in_array($this->type, $allowed, true)) {
            $this->type = $allowed[0];
        }
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'note']);
        $this->observance = $this->whose === 'all' ? Holiday::PHILIPPINES : $this->whose;
        $this->type = array_key_first(Holiday::typesFor($this->observance));

        // Starts on the year being viewed rather than today, so adding next
        // year's proclamation does not mean retyping the year sixteen times.
        $this->date = $this->year === (int) now()->year
            ? now()->toDateString()
            : $this->year . '-01-01';

        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $holiday = Holiday::findOrFail($id);

        $this->editingId = $holiday->id;
        $this->date = $holiday->date->toDateString();
        $this->name = $holiday->name;
        $this->type = $holiday->type;
        $this->observance = $holiday->observance;
        $this->note = (string) $holiday->note;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
            'observance' => ['required', 'in:' . implode(',', array_keys(Holiday::OBSERVANCES))],
            // Scoped to the observance, not the whole list — a US holiday must
            // not be storable as a Philippine Labor Code category.
            'type' => ['required', 'in:' . implode(',', array_keys(Holiday::typesFor($this->observance)))],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['date' => 'date', 'name' => 'holiday name', 'observance' => 'whose holiday']);

        $clash = Holiday::where('date', $data['date'])
            ->where('name', $data['name'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('name', 'That holiday is already on the list for this date.');

            return;
        }

        Holiday::updateOrCreate(['id' => $this->editingId], $data);

        // Jump the list to wherever it was just saved, otherwise adding next
        // year's Christmas looks like nothing happened.
        $this->year = (int) Carbon::parse($data['date'])->year;

        $this->showForm = false;
        $this->statusMessage = $this->editingId ? 'Holiday updated.' : 'Holiday added.';
        $this->reset(['editingId', 'name', 'note']);
    }

    public function prepareDelete(array $ids): void
    {
        $this->deleteIds = Holiday::whereIn('id', array_map('intval', $ids))->pluck('id')->all();
        $this->showDelete = $this->deleteIds !== [];
    }

    public function deleteSelected(): void
    {
        Holiday::whereIn('id', $this->deleteIds)->delete();
        $count = count($this->deleteIds);
        $this->deleteIds = [];
        $this->showDelete = false;
        $this->statusMessage = $count . ' holiday(s) removed. Finalized payroll runs keep their existing figures.';
        $this->dispatch('holidays-deleted');
    }

    public function delete(int $id): void
    {
        Holiday::findOrFail($id)->delete();

        $this->statusMessage = 'Holiday removed. Payroll runs already finalized keep the figures they were computed with.';
    }

    public function with(): array
    {
        $years = Holiday::years();

        // Always offer this year and next, so the list can be started from
        // empty and next year's proclamation has somewhere to go.
        $years = array_values(array_unique([
            ...$years,
            (int) now()->year,
            (int) now()->year + 1,
        ]));
        rsort($years);

        return [
            'years' => $years,
            'holidays' => Holiday::forYear($this->year)
                ->when($this->whose !== 'all', fn ($q) => $q->observance($this->whose))
                ->when($this->search !== '', function ($query) {
                    $query->where(function ($query) {
                        $query->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('note', 'like', '%' . $this->search . '%');
                    });
                })
                ->ordered()
                ->paginate($this->perPage()),
            // Counted per calendar rather than per type. "How many days off do
            // we give?" is the question people actually open this page with.
            'counts' => Holiday::forYear($this->year)
                ->selectRaw('observance, count(*) as total')
                ->groupBy('observance')
                ->pluck('total', 'observance'),
            'workingDays' => Holiday::forYear($this->year)
                ->where('type', Holiday::SPECIAL_WORKING)
                ->count(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-4xl">
            <h1 class="text-xl font-bold text-ink-950 dark:text-white">Holidays</h1>
            <p class="mt-1 text-sm font-medium leading-6 text-ink-500 dark:text-ink-400">
                Manage the Philippine, US, and company holidays observed by PHREMS and used during payroll computation.
            </p>
        </div>

        <x-button type="button" wire:click="create" @click="$dispatch('open-phrems-modal', 'showForm')">
            <x-icon name="plus" class="h-4 w-4" /> Add Holiday
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (\App\Models\Holiday::OBSERVANCES as $key => $label)
            <x-card>
                <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $counts[$key] ?? 0 }}</p>
                <p class="mt-1 text-xs font-medium text-[#778599]">
                    {{ match ($key) {
                        'philippines' => 'Set by proclamation each year.',
                        'united_states' => 'US days the company follows.',
                        default => 'Days the company gives on its own.',
                    } }}
                </p>
            </x-card>
        @endforeach

        <x-card>
            <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">Working days</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $workingDays }}</p>
            <p class="mt-1 text-xs font-medium text-[#778599]">On the list, but everyone is still expected in.</p>
        </x-card>
    </div>

    <x-card
        :padding="false"
        class="directory-panel"
        x-data="{ selected: [] }"
        @holidays-deleted.window="selected = []"
    >
        <div class="directory-toolbar">
            <div>
                <h2 class="directory-title">Holiday Directory</h2>
                <p x-show="selected.length > 0" x-cloak class="mt-1 text-xs font-semibold text-amber-600" x-text="`${selected.length} selected`"></p>
            </div>

            <div class="directory-toolbar-actions">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-on:click="if (selected.length === 1) { $dispatch('open-phrems-modal', 'showForm'); $wire.edit(selected[0]); }"
                        x-bind:disabled="selected.length !== 1"
                        x-bind:title="selected.length === 1 ? 'Edit selected holiday' : 'Select one holiday to edit'"
                        x-bind:class="selected.length === 1 ? 'text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                    >
                        <x-icon name="pencil" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        x-on:click="if (selected.length > 0) { $dispatch('open-phrems-modal', 'showDelete'); $wire.prepareDelete(selected); }"
                        x-bind:disabled="selected.length === 0"
                        x-bind:title="selected.length > 0 ? 'Delete selected holidays' : 'Select holidays to delete'"
                        x-bind:class="selected.length > 0 ? 'border-red-200 bg-red-50 text-red-600 hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                    >
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>

                <x-select wire:model.live="year" @change="selected = []" class="h-10 !w-28 text-sm" title="Calendar year">
                    @foreach ($years as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </x-select>

                <label class="directory-search">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        @input="selected = []"
                        placeholder="Search holidays..."
                        class="block h-10 w-full rounded-lg border border-ink-200 bg-white pl-9 pr-3.5 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-400 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white"
                    >
                </label>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-b border-ink-200 px-6 py-3 dark:border-white/10">
            @foreach (['all' => 'All Calendars'] + \App\Models\Holiday::OBSERVANCES as $key => $label)
                <button
                    type="button"
                    wire:click="showOnly('{{ $key }}')"
                    wire:loading.attr="disabled"
                    wire:target="showOnly"
                    @click="selected = []"
                    class="rounded-lg border px-3 py-2 text-xs font-bold transition {{ $whose === $key ? 'border-brand-700 bg-brand-700 text-white shadow-sm' : 'border-ink-200 bg-white text-ink-600 hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="overflow-x-auto transition-opacity duration-150"
             wire:loading.class="opacity-40"
             wire:target="showOnly, year, search, gotoPage, nextPage, previousPage">
            <table class="directory-table">
                <thead class="directory-table-head">
                    <tr>
                        <th class="w-14 px-6 py-4 text-left">
                            <input
                                type="checkbox"
                                class="directory-checkbox"
                                x-bind:checked="selected.length === {{ $holidays->count() }} && {{ $holidays->count() }} > 0"
                                @click="selected = (selected.length === {{ $holidays->count() }}) ? [] : [{{ $holidays->getCollection()->pluck('id')->implode(',') }}].map(String)"
                            >
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Date</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Day</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Holiday</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Calendar</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Type</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Note</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($holidays as $holiday)
                        <tr
                            wire:key="hol-{{ $holiday->id }}"
                            @click="selected = selected.includes('{{ $holiday->id }}') ? selected.filter(id => id !== '{{ $holiday->id }}') : [...selected, '{{ $holiday->id }}']"
                            class="directory-row cursor-pointer"
                            x-bind:class="selected.includes('{{ $holiday->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''"
                        >
                            <td class="px-6 py-4" @click.stop>
                                <input type="checkbox" value="{{ $holiday->id }}" x-model="selected" class="directory-checkbox">
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 font-bold text-ink-800 tabular-nums dark:text-white">{{ $holiday->date->format('M j, Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $holiday->date->format('l') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-ink-800 dark:text-white">{{ $holiday->name }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $holiday->observanceLabel() }}</td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="match ($holiday->type) {
                                    'regular' => 'green',
                                    'special_non_working' => 'blue',
                                    'paid_day_off' => 'green',
                                    default => 'neutral',
                                }">{{ $holiday->typeLabel() }}</x-badge>
                            </td>
                            <td class="min-w-48 px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $holiday->note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="calendar" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No holidays found</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">No {{ $whose === 'all' ? '' : \App\Models\Holiday::OBSERVANCES[$whose] . ' ' }}holidays are recorded for {{ $year }}.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($holidays->hasPages())
            <div class="directory-pagination" @click="selected = []">
                {{ $holidays->links('components.pagination', ['noun' => 'holidays']) }}
            </div>
        @endif
    </x-card>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card>
            <h2 class="text-sm font-bold text-[#0f172a] dark:text-white">US holidays are yours to choose</h2>
            <p class="mt-2 text-sm font-medium text-[#778599]">
                Nothing American is loaded for you, because no two companies follow the same set. Add the ones
                this company actually observes — Thanksgiving, the Fourth of July, whatever your clients close
                for — and leave the rest off. A day that is not on this list is an ordinary workday.
            </p>
            <p class="mt-2 text-sm font-medium text-[#778599]">
                When a US holiday falls on a weekend it is usually observed on the nearest weekday. Enter the
                date your staff actually take off, not the calendar date.
            </p>
        </x-card>

        <x-card>
            <h2 class="text-sm font-bold text-[#0f172a] dark:text-white">Two Philippine holidays move every year</h2>
            <p class="mt-2 text-sm font-medium text-[#778599]">
                <span class="font-bold text-[#0f172a] dark:text-white">Eid'l Fitr and Eid'l Adha</span> follow the lunar
                calendar and are announced only a few days ahead, and the
                <span class="font-bold text-[#0f172a] dark:text-white">extra special days</span> the President adds each
                year — usually 24 December and 2 November — change from year to year. Add them here when the
                proclamation comes out.
            </p>
        </x-card>
    </div>

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
            {{ $editingId ? 'Edit Holiday' : 'Add Holiday' }}
        </h2>

        <div class="mt-5 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Date</x-label>
                    <x-input wire:model="date" type="date" />
                    @error('date') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Whose holiday</x-label>
                    <x-select wire:model.live="observance">
                        @foreach (\App\Models\Holiday::OBSERVANCES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                    @error('observance') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-label>Holiday name</x-label>
                <x-input wire:model="name" type="text"
                         placeholder="{{ $observance === 'united_states' ? 'e.g. Thanksgiving Day' : ($observance === 'company' ? 'e.g. Company Anniversary' : 'e.g. Araw ng Kagitingan') }}" />
                @error('name') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Type</x-label>
                {{-- The options change with the calendar above. Only Philippine
                     holidays carry Labor Code categories. --}}
                <x-select wire:model="type">
                    @foreach (\App\Models\Holiday::typesFor($observance) as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-select>
                @error('type') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="note" type="text" placeholder="e.g. Proclamation No. 727" />
                <p class="mt-1 text-xs font-medium text-[#778599]">For your own reference. Payroll ignores it.</p>
                @error('note') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-lg bg-[#f8fafc] p-3 text-xs font-medium text-[#778599] dark:bg-neutral-800/50">
                @if ($observance === 'philippines')
                    <span class="font-bold text-[#0f172a] dark:text-white">Regular</span> and
                    <span class="font-bold text-[#0f172a] dark:text-white">Special (Non-Working)</span> days keep an
                    employee's pay when they stay home.
                    <span class="font-bold text-[#0f172a] dark:text-white">Special (Working)</span> is the government
                    saying it is an ordinary day, so not turning up is still an absence.
                @else
                    <span class="font-bold text-[#0f172a] dark:text-white">Paid Day Off</span> keeps an employee's pay
                    when they stay home.
                    <span class="font-bold text-[#0f172a] dark:text-white">Special (Working)</span> puts the day on the
                    list for everyone to see while still expecting them in — useful for a client holiday your staff
                    work through.
                @endif
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="save">{{ $editingId ? 'Save Changes' : 'Add Holiday' }}</x-button>
            <x-button wire:click="$set('showForm', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
    <x-modal wire="showDelete" onClose="$set('showDelete', false)" maxWidth="lg">
        <div class="flex items-start gap-4">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-600 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300">
                <x-icon name="trash" class="h-5 w-5" />
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-red-600 dark:text-red-300">Delete Confirmation</p>
                <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Delete selected holidays?</h2>
                <p class="mt-2 text-sm font-medium leading-6 text-ink-600 dark:text-ink-300">
                    {{ count($deleteIds) }} holiday(s) will be removed. Payroll will treat those dates as ordinary workdays in future computations.
                </p>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2 border-t border-ink-100 pt-4 dark:border-white/10">
            <x-button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-button>
            <x-button wire:click="deleteSelected" wire:loading.attr="disabled" wire:target="deleteSelected" variant="danger" class="min-w-32">
                <span wire:loading.remove wire:target="deleteSelected">Delete Holidays</span>
                <span wire:loading wire:target="deleteSelected">Deleting...</span>
            </x-button>
        </div>
    </x-modal>
</div>
